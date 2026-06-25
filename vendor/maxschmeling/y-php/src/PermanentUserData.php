<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateUtils;

final class PermanentUserData
{
    /** @var array<int, string> */
    private array $clients = [];
    /** @var array<string, array<int, list<array{clock: int, length: int}>>> */
    private array $deleteSets = [];
    /** @var array<string, list<string>> */
    private array $encodedDeleteSets = [];
    /** @var array<string, array{ids: YNestedArray, ds: YNestedArray}> */
    private array $users = [];

    public function __construct(private readonly YDoc $doc, private readonly string $storeName = 'users')
    {
        $this->hydrateFromDocument();
    }

    /**
     * @param callable(array<string, mixed>, array<int, list<array{clock: int, length: int}>>): bool|null $filter
     */
    public function setUserMapping(YDoc $doc, int $clientId, string $userDescription, ?callable $filter = null): void
    {
        if ($clientId < 0) {
            throw new \InvalidArgumentException('Permanent user data client IDs must be non-negative.');
        }

        $user = $this->userStore($userDescription);
        $user['ids']->push([$clientId]);
        $this->clients[$clientId] = $userDescription;

        $doc->observeTransaction(function (array $event) use ($filter, $userDescription): void {
            if (! ($event['local'] ?? false)) {
                return;
            }

            $decoded = DecodedUpdate::decodeV1((string) $event['update']);
            $deleteSet = $decoded['deleteSet'];
            if ($deleteSet === []) {
                return;
            }

            if ($filter !== null && ! $filter($event, $deleteSet)) {
                return;
            }

            $this->addDeleteSet($userDescription, $deleteSet, true);
        });
    }

    public function getUserByClientId(int $clientId): ?string
    {
        return $this->clients[$clientId] ?? null;
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    public function getUserByDeletedId(array $id): ?string
    {
        foreach ($this->deleteSets as $userDescription => $deleteSet) {
            foreach ($deleteSet[$id['client']] ?? [] as $delete) {
                if ($id['clock'] >= $delete['clock'] && $id['clock'] < $delete['clock'] + $delete['length']) {
                    return $userDescription;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function encodedDeleteSets(string $userDescription): array
    {
        return $this->encodedDeleteSets[$userDescription] ?? [];
    }

    /**
     * @return array{
     *     clients: array<int, string>,
     *     deleteSets: array<string, array<int, list<array{clock: int, length: int}>>>,
     *     encodedDeleteSets: array<string, list<string>>
     * }
     */
    public function toArray(): array
    {
        ksort($this->clients, SORT_NUMERIC);

        return [
            'clients' => $this->clients,
            'deleteSets' => $this->deleteSets,
            'encodedDeleteSets' => $this->encodedDeleteSets,
        ];
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    public function addDeleteSet(string $userDescription, array $deleteSet, bool $storeInDocument = false): void
    {
        $deleteSet = UpdateUtils::mergeDeleteSets([$deleteSet]);
        if ($deleteSet === []) {
            return;
        }

        $encoded = self::encodeDeleteSet($deleteSet);
        $this->deleteSets[$userDescription] = UpdateUtils::mergeDeleteSets([
            $this->deleteSets[$userDescription] ?? [],
            $deleteSet,
        ]);
        $this->encodedDeleteSets[$userDescription][] = $encoded;

        if ($storeInDocument) {
            $this->userStore($userDescription)['ds']->push([$this->bytesValue($encoded)]);
        }
    }

    public function addEncodedDeleteSet(string $userDescription, string $encodedDeleteSet): void
    {
        $this->deleteSets[$userDescription] = UpdateUtils::mergeDeleteSets([
            $this->deleteSets[$userDescription] ?? [],
            self::decodeDeleteSet($encodedDeleteSet),
        ]);
        $this->encodedDeleteSets[$userDescription][] = $encodedDeleteSet;
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    public static function encodeDeleteSet(array $deleteSet): string
    {
        $deleteSet = UpdateUtils::mergeDeleteSets([$deleteSet]);
        $encoder = new Encoding();
        $encoder->writeVarUint(count($deleteSet));

        foreach ($deleteSet as $client => $deletes) {
            $encoder->writeVarUint((int) $client);
            $encoder->writeVarUint(count($deletes));

            foreach ($deletes as $delete) {
                $encoder->writeVarUint($delete['clock']);
                $encoder->writeVarUint($delete['length']);
            }
        }

        return $encoder->toString();
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    public static function decodeDeleteSet(string $encodedDeleteSet): array
    {
        $decoder = new Decoding($encodedDeleteSet);
        $numClients = $decoder->readVarUint();
        $deleteSet = [];

        for ($i = 0; $i < $numClients; $i++) {
            $client = $decoder->readVarUint();
            $numDeletes = $decoder->readVarUint();

            for ($deleteIndex = 0; $deleteIndex < $numDeletes; $deleteIndex++) {
                $deleteSet[$client][] = [
                    'clock' => $decoder->readVarUint(),
                    'length' => $decoder->readVarUint(),
                ];
            }
        }

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Permanent user data delete set contains trailing bytes.');
        }

        return UpdateUtils::mergeDeleteSets([$deleteSet]);
    }

    /**
     * @return array{ids: YNestedArray, ds: YNestedArray}
     */
    private function userStore(string $userDescription): array
    {
        if (isset($this->users[$userDescription])) {
            return $this->users[$userDescription];
        }

        $user = $this->doc->getMap($this->storeName)->setMap($userDescription);
        $this->users[$userDescription] = [
            'ids' => $user->setArray('ids'),
            'ds' => $user->setArray('ds'),
        ];

        return $this->users[$userDescription];
    }

    private function hydrateFromDocument(): void
    {
        foreach ($this->doc->getMap($this->storeName)->toArray() as $userDescription => $userStore) {
            if (! is_string($userDescription) || ! is_array($userStore)) {
                continue;
            }

            foreach (($userStore['ids'] ?? []) as $clientId) {
                if (is_int($clientId)) {
                    $this->clients[$clientId] = $userDescription;
                }
            }

            foreach (($userStore['ds'] ?? []) as $encodedDeleteSet) {
                $bytes = $this->bytesFromValue($encodedDeleteSet);
                if ($bytes !== null) {
                    $this->addEncodedDeleteSet($userDescription, $bytes);
                }
            }
        }
    }

    private function bytesFromValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (
            is_array($value)
            && ($value['type'] ?? null) === 'Uint8Array'
            && isset($value['base64'])
            && is_string($value['base64'])
        ) {
            $bytes = base64_decode($value['base64'], true);

            return is_string($bytes) ? $bytes : null;
        }

        if (is_array($value) && array_is_list($value)) {
            $bytes = '';

            foreach ($value as $byte) {
                if (! is_int($byte) || $byte < 0 || $byte > 0xff) {
                    return null;
                }

                $bytes .= chr($byte);
            }

            return $bytes;
        }

        return null;
    }

    /**
     * @return array{type: string, base64: string}
     */
    private function bytesValue(string $bytes): array
    {
        return [
            'type' => 'Uint8Array',
            'base64' => base64_encode($bytes),
        ];
    }
}
