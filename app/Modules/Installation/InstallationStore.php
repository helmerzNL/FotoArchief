<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Closure;
use RuntimeException;

final class InstallationStore
{
    public function __construct(public readonly string $directory) {}

    public function read(): ?InstallationState
    {
        $file = $this->directory.'/state.json';
        if (! is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException('Installatiestatus kan niet worden gelezen.');
        }

        return InstallationState::decode($json);
    }

    public function initialize(): InstallationState
    {
        return $this->locked(function (): InstallationState {
            $state = $this->read();
            if ($state !== null) {
                return $state;
            }
            if (is_file($this->directory.'/setup-code.txt')) {
                throw new RuntimeException('Installatiestatus ontbreekt terwijl er al een installatiecode bestaat. Herstel de private installatiemap; de installatie wordt niet opnieuw geopend.');
            }
            $code = bin2hex(random_bytes(24));
            $state = new InstallationState(
                bin2hex(random_bytes(16)),
                'base64:'.base64_encode(random_bytes(32)),
                hash('sha256', $code),
            );
            $this->writePrivate('setup-code.txt', $code."\n");
            $this->save($state);

            return $state;
        });
    }

    public function save(InstallationState $state): void
    {
        $this->writePrivate('state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    public function completed(): bool
    {
        return $this->read()?->phase === 'complete';
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function locked(Closure $operation): mixed
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Private installatiemap kan niet worden aangemaakt.');
        }
        $handle = fopen($this->directory.'/installation.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Installatievergrendeling kan niet worden geopend.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Er is al een installatie actief. Probeer het straks opnieuw.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writePrivate(string $name, string $content): void
    {
        $temporary = tempnam($this->directory, 'install-');
        if ($temporary === false) {
            throw new RuntimeException('Tijdelijke installatieconfiguratie kan niet worden aangemaakt.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Private installatieconfiguratie kan niet worden geschreven.');
            }
            if (! rename($temporary, $this->directory.'/'.$name)) {
                throw new RuntimeException('Installatieconfiguratie kan niet atomair worden opgeslagen.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
