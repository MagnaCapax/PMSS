<?php
/** Verify add-only Prowlarr payloads against synthetic provider schemas without HTTP. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackProwlarrPayloadTest extends TestCase
{
    public function testNewLinksRetainSchemaCategoriesAndUseAccountUrlsAndKeys(): void
    {
        foreach (['Radarr', 'Sonarr'] as $app) {
            [$rc, $payload] = $this->payload('[]', json_encode([$this->schema($app)]), $app);
            $this->assertSame(0, $rc);
            $data = json_decode($payload, true);
            $this->assertSame($app, $data['name']);
            $this->assertSame('fullSync', $data['syncLevel']);
            $this->assertSame([], $data['tags']);
            $this->assertFalse(isset($data['id']));
            $this->assertFalse(isset($data['presets']));
            $fields = array_column($data['fields'], 'value', 'name');
            $this->assertSame('http://127.0.0.1:23001/public-fixtur01/'.strtolower($app), $fields['baseUrl']);
            $this->assertSame('http://127.0.0.1:23003/public-fixtur01/prowlarr', $fields['prowlarrUrl']);
            $this->assertSame(str_repeat('a', 32), $fields['apiKey']);
            $this->assertSame([2000, 5000], $fields['syncCategories']);
            $this->assertSame(true, $fields['schemaDefault']);
        }
    }

    public function testRenamedDisabledAndNameCollidingApplicationsArePreserved(): void
    {
        foreach ([['implementation' => 'Radarr', 'name' => 'Customer films', 'syncLevel' => 'disabled'],
            ['implementation' => 'Sonarr', 'name' => 'radarr'],
            ['implementation' => 'radarr', 'name' => 'Custom', 'tags' => [42]]] as $entry) {
            [$rc, $payload] = $this->payload(json_encode([$entry]), json_encode([$this->schema()]));
            $this->assertSame(2, $rc);
            $this->assertSame('', $payload);
        }
    }

    public function testMalformedApplicationListsNeverBecomeAnEmptyInstallation(): void
    {
        foreach (['', '{broken', '{}', 'null', '[null]', '[{"name":"Radarr"}]',
            '[{"implementation":[],"name":"Custom"}]'] as $existing) {
            [$rc, $payload] = $this->payload($existing, json_encode([$this->schema()]));
            $this->assertSame(1, $rc);
            $this->assertSame('', $payload);
        }
    }

    public function testUnknownIncompleteAndDuplicateSchemasRefuseMutation(): void
    {
        $schema = $this->schema();
        $missing = $schema;
        array_pop($missing['fields']);
        array_shift($missing['fields']);
        $duplicate = $schema;
        $duplicate['fields'][] = $schema['fields'][0];
        foreach (['{broken', '{}', '[]', json_encode([$this->schema('Other')]),
            json_encode([$schema, $schema]), json_encode([$missing]), json_encode([$duplicate]),
            json_encode([array_replace($schema, ['configContract' => 'OtherSettings'])]),
            json_encode([array_replace($schema, ['fields' => null])])] as $schemas) {
            [$rc, $payload] = $this->payload('[]', $schemas);
            $this->assertSame(1, $rc);
            $this->assertSame('', $payload);
        }
    }

    public function testInvalidKeysNeverEnterPayloads(): void
    {
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('z', 32), "bad\nkey"] as $key) {
            [$rc, $payload] = $this->payload('[]', json_encode([$this->schema()]), 'Radarr', $key);
            $this->assertSame(1, $rc);
            $this->assertSame('', $payload);
        }
    }

    private function schema(string $app = 'Radarr'): array
    {
        return ['implementation' => $app, 'configContract' => $app.'Settings', 'id' => 0,
            'presets' => [], 'fields' => [
                ['name' => 'baseUrl', 'value' => 'http://localhost:7878'],
                ['name' => 'prowlarrUrl', 'value' => 'http://localhost:9696'],
                ['name' => 'apiKey', 'value' => ''], ['name' => 'syncCategories', 'value' => [2000, 5000]],
                ['name' => 'schemaDefault', 'value' => true],
            ]];
    }

    private function payload(string $existing, string $schema, string $app = 'Radarr', ?string $key = null): array
    {
        $dir = $this->pmssMakeTempDir('pmss-prowlarr-payload-');
        $this->pmssWriteRelativeFile($dir, 'existing', $existing);
        $this->pmssWriteRelativeFile($dir, 'schema', $schema);
        $this->pmssWriteRelativeFile($dir, 'curl', 'header = "X-Api-Key: '.($key ?? str_repeat('a', 32)).'"');
        $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\n"
            .$this->pmssReadRepoFile('etc/skel/bin/mediaStackProwlarr.sh')."\n"
            .'cd '.escapeshellarg($dir)."\n"
            .'if media_stack_prowlarr_payload_write existing schema curl '.escapeshellarg($app).' '
            .escapeshellarg('http://127.0.0.1:23001/public-fixtur01/'.strtolower($app)).' '
            .escapeshellarg('http://127.0.0.1:23003/public-fixtur01/prowlarr')
            .' >payload; then echo 0; else echo $?; fi'."\n");
        return [(int) trim($output), file_get_contents($dir.'/payload')];
    }
}
