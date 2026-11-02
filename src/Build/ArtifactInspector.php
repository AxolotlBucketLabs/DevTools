<?php

declare(strict_types=1);

namespace NhanAZ\DevTools\Build;

use NhanAZ\DevTools\Validation\PluginManifestReader;
use NhanAZ\DevTools\Virion\VirionManifestReader;
use Phar;

final class ArtifactInspector
{
    public function __construct(
        private readonly PluginManifestReader $manifestReader,
        private readonly ?VirionManifestReader $virionManifestReader = null,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new BuildException("Artifact does not exist: {$path}");
        }
        $phar = new Phar($real);
        $prefix = 'phar://' . str_replace('\\', '/', $real) . '/';
        $hasPlugin = isset($phar['plugin.yml']);
        $hasVirion = isset($phar['virion.yml']);
        if (!$hasPlugin && !$hasVirion) {
            throw new BuildException("Artifact is missing plugin.yml and virion.yml: {$path}");
        }
        if ($hasVirion && $this->virionManifestReader === null) {
            throw new BuildException('Virion artifact inspection is not configured.');
        }
        $type = $hasPlugin && $hasVirion ? ProjectRole::DUAL->value : ($hasPlugin ? ProjectRole::PLUGIN->value : ProjectRole::VIRION->value);
        $data = [
            'artifact' => $real,
            'type' => $type,
            'files' => count($phar),
        ];
        if ($hasPlugin) {
            $manifest = $this->manifestReader->read($prefix . 'plugin.yml');
            $main = $manifest->expectedMainRelativePath();
            if ($main === null || !isset($phar['src/' . $main])) {
                throw new BuildException("Artifact is missing its declared main class source: {$path}");
            }
            $data['plugin'] = ['name' => $manifest->description->getName(), 'version' => $manifest->description->getVersion()];
        }
        if ($hasVirion) {
            $manifest = $this->virionManifestReader->read($prefix . 'virion.yml');
            if (!isset($phar['src'])) {
                throw new BuildException("Artifact is missing its virion source directory: {$path}");
            }
            $data['virion'] = ['name' => $manifest->name, 'version' => $manifest->version, 'antigen' => $manifest->antigen];
        }
        /** @var array{hash: string, hash_type: string}|false $signature */
        $signature = $phar->getSignature();
        if ($signature === false || $signature['hash_type'] === '') {
            throw new BuildException("Artifact has no PHAR signature: {$path}");
        }
        $hash = hash_file('sha256', $real);
        if ($hash === false) {
            throw new BuildException("Cannot hash artifact: {$path}");
        }

        return $data + ['sha256' => $hash, 'signature' => $signature['hash_type']];
    }

}
