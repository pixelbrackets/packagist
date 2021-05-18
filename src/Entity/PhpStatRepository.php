<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use DateTimeImmutable;

class PhpStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private Client $redis)
    {
        parent::__construct($registry, PhpStat::class);
    }

    public function deletePackageStats(Package $package)
    {
        $conn = $this->getEntityManager()->getConnection();

        $conn->executeStatement('DELETE FROM php_stat WHERE package_id = :id', ['id' => $package->getId()]);
    }

    public function transferStatsToDb(int $packageId, array $keys, DateTimeImmutable $now): void
    {
        $package = $this->getEntityManager()->getRepository(Package::class)->find($packageId);
        // package was deleted in the meantime, abort
        if (!$package) {
            $this->redis->del($keys);
            return;
        }

        sort($keys);

        $values = $this->redis->mget($keys);

        $buffer = [];
        $lastPrefix = null;

        foreach ($keys as $index => $key) {
            // strip php minor version and date from the key to get the primary prefix (i.e. type:package-version:*)
            $prefix = preg_replace('{:\d+\.\d+:\d+$}', ':', $key);

            if ($lastPrefix && $prefix !== $lastPrefix && $buffer) {
                $this->createDbRecordsForKeys($package, $buffer, $now);
                $this->redis->del(array_keys($buffer));
                $buffer = [];
            }

            $buffer[$key] = (int) $values[$index];
            $lastPrefix = $prefix;
        }

        if ($buffer) {
            $this->createDbRecordsForKeys($package, $buffer, $now);
            $this->redis->del(array_keys($buffer));
        }
    }

    /**
     * @param array<string, int> $keys array of keys => dl count
     */
    private function createDbRecordsForKeys(Package $package, array $keys, DateTimeImmutable $now): void
    {
        reset($keys);
        $info = $this->getKeyInfo($package, key($keys));

        $record = $this->createOrUpdateRecord($package, $info['type'], $info['version'], $keys, $now);
        // create an aggregate major version data point by summing up all the minor versions under it
        if ($record->getDepth() === PhpStat::DEPTH_MINOR && preg_match('{^\d+}', $record->getVersion(), $match)) {
            $this->createOrUpdateRecord($package, $info['type'], $match[0], $keys, $now);
        }
    }

    private function createOrUpdateRecord(Package $package, int $type, string $version, array $keys, DateTimeImmutable $now): PhpStat
    {
        $record = $this->getEntityManager()->getRepository(PhpStat::class)->findOneBy(['package' => $package, 'type' => $type, 'version' => $version]);

        if ($record) {
            $record->setLastUpdated($now);
        } else {
            $record = new PhpStat($package, $type, $version);
        }

        foreach ($keys as $key => $val) {
            if (!$val) {
                continue;
            }

            $pointInfo = $this->getKeyInfo($package, $key);
            if (($pointInfo['version'] !== $version && !str_starts_with($pointInfo['version'], $version)) || $pointInfo['type'] !== $type) {
                throw new \LogicException('Version or type mismatch, somehow the key grouping in buffer failed, got '.json_encode($pointInfo).' and '.json_encode(['type' => $type, 'version' => $version]));
            }
            $record->addDataPoint($pointInfo['phpversion'], $pointInfo['date'], $val);
        }

        $this->getEntityManager()->persist($record);
        $this->getEntityManager()->flush();

        return $record;
    }

    /**
     * @return array{type: PhpStat::TYPE_*, version: string, phpversion: string, date: string, package: int}
     */
    private function getKeyInfo(Package $package, string $key): array
    {
        if (!preg_match('{^php(?<platform>platform)?:(?<package>\d+)-(?<version>.+):(?<phpversion>\d+\.\d+|hhvm):(?<date>\d+)$}', $key, $match)) {
            throw new \LogicException('Could not parse key: '.$key);
        }

        if ((int) $match['package'] !== $package->getId()) {
            throw new \LogicException('Expected keys for package id '.$package->getId().', got '.$key);
        }

        return [
            'type' => $match['platform'] === 'platform' ? PhpStat::TYPE_PLATFORM : PhpStat::TYPE_PHP,
            'version' => $match['version'],
            'phpversion' => $match['phpversion'],
            'date' => $match['date'],
            'package' => (int) $match['package'],
        ];
    }
}