<?php

namespace Tests\Feature;

use App\Models\LuantiAccountAssignment;
use App\Models\LuantiAccountJob;
use App\Models\LuantiServer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\BridgeHttpClient;
use App\Services\LuantiAccounts;
use App\Services\LuantiGameInstances;
use App\Services\LuantiSshClient;
use App\Services\LuantiWorldTemplateSynchronizer;
use App\Services\WorldRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

class BridgeDevelopmentLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_windows_portal_approval_accounts_expiry_and_tls_against_the_selected_test_vm(): void
    {
        $id = getenv('BRIDGE_DEV_LIVE_SERVER');
        if (! is_string($id) || ! ctype_digit($id)) {
            $this->markTestSkipped('Opt in with BRIDGE_DEV_LIVE_SERVER after bridge test and bridge up. Uses a disposable portal database and an explicit remote test template.');
        }
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertFileExists(database_path('database.sqlite'));
        $database = new PDO('sqlite:'.database_path('database.sqlite'));
        $statement = $database->prepare('SELECT * FROM luanti_servers WHERE id = ?');
        $statement->execute([(int) $id]);
        $attributes = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($attributes);
        $remote = new LuantiServer;
        $remote->setRawAttributes($attributes, true);
        $this->assertSame('https://localhost:18443', $remote->api_base_url);
        $this->app['env'] = 'local';
        Queue::fake();
        $ssh = app(LuantiSshClient::class);
        $templateId = 'e2e-'.bin2hex(random_bytes(8));
        $setup = 'TEMPLATE_ID = "'.$templateId.'"'."\n".<<<'PY'
from pathlib import Path
import json, sqlite3, sys
from contextlib import closing
base=Path('/var/lib/luanti-portal/bridge-development')
active=json.loads((base/'active.json').read_text())
sys.path.insert(0,active['source'])
from tools.publish_template import publish
from lifecycle.install_docker import install, directory
fixture=base/'explicit-e2e-templates'/TEMPLATE_ID
directory(fixture)
source=fixture/'clean-source'
directory(source)
(source/'world.mt').write_text('gameid = minetest\nbackend = sqlite3\nauth_backend = sqlite3\nload_mod_civicrm_bridge = mods/civicrm_bridge\n')
with closing(sqlite3.connect(source/'map.sqlite')) as db:
 db.execute('CREATE TABLE blocks (pos INTEGER PRIMARY KEY, data BLOB)')
publish(source,fixture/'published',TEMPLATE_ID,'Explicit Docker end-to-end test')
settings={key:value for key,value in active['settings'].items() if key!='game_sha256'}
settings['templates']={TEMPLATE_ID:str(fixture/'published')}
result=install(settings,apply=True)
import subprocess
subprocess.run(['/usr/bin/systemctl','start','civicrm-bridge-development-proxy.service'],check=True,capture_output=True,timeout=15)
print(json.dumps({'ok':result['applied']}))
PY;
        $this->assertTrue(json_decode($ssh->execute($remote, $setup, 600), true, flags: JSON_THROW_ON_ERROR)['ok']);
        $actor = User::factory()->globalAdmin()->create();
        $server = LuantiServer::factory()->create(['api_base_url' => $remote->api_base_url, 'api_token' => $remote->api_token]);
        $instanceId = null;
        try {
            $ssh->tunnel($remote, function () use ($actor, $server, $templateId, &$instanceId): void {
                $http = app(BridgeHttpClient::class);
                $this->assertSame(401, $http->send('GET', $server->api_base_url.'/v1/world-templates', str_repeat('z', 43), 5)->status());
                $certificate = config('services.bridge_development.ca_certificate');
                config(['services.bridge_development.ca_certificate' => __FILE__]);
                try {
                    $http->send('GET', $server->api_base_url.'/v1/world-templates', $server->api_token, 5);
                    $this->fail('An incorrect CA was accepted.');
                } catch (ConnectionException) {
                    $this->assertTrue(true);
                } finally {
                    config(['services.bridge_development.ca_certificate' => $certificate]);
                }
                $sync = app(LuantiWorldTemplateSynchronizer::class)->sync($actor, $server);
                $this->assertTrue($sync['successful'], $sync['body']);
                $template = $server->templates()->where('template_identifier', $templateId)->firstOrFail();
                $organization = Organization::factory()->create();
                $applicant = User::factory()->create();
                OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $applicant->id]);
                $request = app(WorldRequests::class)->create($applicant, $organization, [
                    'name' => 'Disposable Docker acceptance test', 'luanti_world_template_id' => $template->id,
                    'start_date' => now('Europe/Berlin')->toDateString(), 'end_date' => now('Europe/Berlin')->toDateString(), 'participant_count' => 1,
                ]);
                app(WorldRequests::class)->decide($actor, $request, true, $request->revision);
                $request = $request->fresh();
                $instanceId = $request->currentGameInstance->instance_identifier;
                $access = $request->approved_access;
                $access['starts_at'] = time();
                $access['ends_at'] = $access['starts_at'] + 120;
                $access['generation']++;
                $request->forceFill(['approved_access' => $access])->save();
                $deadline = microtime(true) + 60;
                do {
                    app(LuantiGameInstances::class)->reconcile($request->id);
                    $instance = $request->fresh()->currentGameInstance;
                    if ($instance->state === 'failed') {
                        $this->fail('Live private instance failed: '.$instance->error);
                    }
                    if ($instance->state === 'running') {
                        break;
                    }
                    usleep(250000);
                } while (microtime(true) < $deadline);
                $this->assertSame('running', $instance->fresh()->state);
                $instanceId = $instance->instance_identifier;
                app(LuantiAccounts::class)->create($actor, $request->fresh(), [[
                    'username' => 'docker_e2e', 'password' => 'FictitiousDockerE2eOnly!', 'role' => 'participant',
                ]]);
                $deadline = microtime(true) + 30;
                do {
                    app(LuantiAccounts::class)->reconcile($request->id);
                    foreach (LuantiAccountJob::where('world_request_id', $request->id)->whereIn('state', ['queued', 'pending'])->get() as $job) {
                        app(LuantiAccounts::class)->process($job->id);
                    }
                    if (LuantiAccountAssignment::where('luanti_game_instance_id', $instance->id)->where('is_provisioned', true)->exists()) {
                        break;
                    }
                    usleep(250000);
                } while (microtime(true) < $deadline);
                $this->assertTrue(LuantiAccountAssignment::where('luanti_game_instance_id', $instance->id)->where('is_provisioned', true)->exists());
                $access = $request->fresh()->approved_access;
                $access['ends_at'] = time() + 5;
                $access['generation']++;
                $request->forceFill(['approved_access' => $access])->save();
                $deadline = microtime(true) + 25;
                do {
                    app(LuantiGameInstances::class)->reconcile($request->id);
                    if (time() >= $access['ends_at'] && $instance->fresh()->state === 'stopped') {
                        break;
                    }
                    usleep(250000);
                } while (microtime(true) < $deadline);
                $this->assertSame('stopped', $instance->fresh()->state);
            });
        } finally {
            if ($instanceId !== null) {
                $this->archiveOwnInstance($remote, $instanceId, $templateId);
            }
        }
        $this->assertIsString($instanceId);
        $check = 'INSTANCE_ID = "'.$instanceId.'"'."\n".<<<'PY'
from pathlib import Path
import json
world=Path('/var/lib/civicrm-luanti/instances')/INSTANCE_ID
print(json.dumps({'save_retained':(world/'map.sqlite').is_file(),'accounts_retained':(world/'auth.sqlite').is_file()}))
PY;
        $retained = json_decode($ssh->execute($remote, $check), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($retained['save_retained']);
        $this->assertTrue($retained['accounts_retained']);
    }

    private function archiveOwnInstance(LuantiServer $server, string $instanceId, string $templateId): void
    {
        $script = 'INSTANCE_ID = "'.$instanceId.'"'."\n".'TEMPLATE_ID = "'.$templateId.'"'."\n".<<<'PY'
from pathlib import Path
import json, subprocess, sys, time, uuid
sys.path.insert(0,'/usr/local/lib/civicrm-worldctl')
from instances import registry
from controller import trusted_json
config=trusted_json('/etc/civicrm-worldctl/config.json')
record=registry(config).get(INSTANCE_ID)
if record is not None:
 if record['creation']['template_id']!=TEMPLATE_ID:
  raise ValueError('Refusing to archive an unrelated instance')
 raw=json.dumps({'version':2,'job_id':'live-cleanup-'+uuid.uuid4().hex,'operation':'archive_instance','instance_id':INSTANCE_ID}).encode()
 for attempt in range(20):
  result=subprocess.run(['/usr/local/sbin/civicrm-worldctl','instance'],input=raw,capture_output=True,timeout=60)
  response=json.loads(result.stdout)
  if response.get('ok'):
   break
  if response.get('error',{}).get('code')!='busy':
   raise ValueError('Test instance archival failed')
  time.sleep(0.2)
 else:
  raise ValueError('Test instance archival remained busy')
print(json.dumps({'archived':True}))
PY;
        $this->assertTrue(json_decode(app(LuantiSshClient::class)->execute($server, $script, 90), true, flags: JSON_THROW_ON_ERROR)['archived']);
    }
}
