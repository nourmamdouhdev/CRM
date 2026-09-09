<?php
require __DIR__ . '/bootstrap.php';
authorize($config['authz']['integrations_manage'] ?? [ROLE_OWNER]);

use App\Domain\Integrations\IntegrationFactory;
use App\Infrastructure\Persistence\SettingsRepository;

$title = 'Integrations';
$subtitle = 'WhatsApp Cloud API + Claude MCP connectivity';

$error = null;
$success = null;
$generatedMcpToken = null;
$testResults = [];

$settings = IntegrationFactory::settings($pdo, $config);
$whatsApp = IntegrationFactory::whatsApp($pdo, $config);
$mcp = IntegrationFactory::mcp($pdo, $config);

$saveOptional = static function (SettingsRepository $settings, string $key, string $value): void {
    if (trim($value) !== '') {
        $settings->set($key, $value);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::verify($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_whatsapp') {
            $enabled = isset($_POST['whatsapp_enabled']);
            $phoneNumberId = trim((string)($_POST['whatsapp_phone_number_id'] ?? ''));
            $businessAccountId = trim((string)($_POST['whatsapp_business_account_id'] ?? ''));
            $verifyToken = trim((string)($_POST['whatsapp_verify_token'] ?? ''));
            $apiVersion = trim((string)($_POST['whatsapp_api_version'] ?? 'v21.0'));
            $country = trim((string)($_POST['whatsapp_default_country_code'] ?? '20'));
            $accessToken = trim((string)($_POST['whatsapp_access_token'] ?? ''));
            $appSecret = trim((string)($_POST['whatsapp_app_secret'] ?? ''));

            if ($enabled && $phoneNumberId === '') {
                throw new InvalidArgumentException('Phone Number ID is required to activate WhatsApp.');
            }
            if ($enabled && $settings->getString('whatsapp.access_token') === '' && $accessToken === '') {
                throw new InvalidArgumentException('Access token is required to activate WhatsApp.');
            }
            if ($verifyToken === '') {
                $verifyToken = bin2hex(random_bytes(16));
            }

            $settings->set('whatsapp.enabled', $enabled);
            $settings->set('whatsapp.phone_number_id', $phoneNumberId);
            $settings->set('whatsapp.business_account_id', $businessAccountId);
            $settings->set('whatsapp.verify_token', $verifyToken);
            $settings->set('whatsapp.api_version', $apiVersion !== '' ? $apiVersion : 'v21.0');
            $settings->set('whatsapp.default_country_code', $country !== '' ? $country : '20');
            $saveOptional($settings, 'whatsapp.access_token', $accessToken);
            $saveOptional($settings, 'whatsapp.app_secret', $appSecret);

            audit_log('whatsapp_configured', 'integrations', 1, [
                'enabled' => $enabled,
                'phone_number_id' => $phoneNumberId,
            ]);

            header("Location: {$base}/integrations.php?ok=whatsapp");
            exit;
        }

        if ($action === 'save_mcp') {
            $mcpEnabled = isset($_POST['mcp_enabled']);
            $claudeEnabled = isset($_POST['claude_enabled']);
            $serverName = trim((string)($_POST['mcp_server_name'] ?? 'tagom-crm'));
            $model = trim((string)($_POST['claude_model'] ?? 'claude-sonnet-4-5'));
            $apiKey = trim((string)($_POST['claude_api_key'] ?? ''));
            $rotate = isset($_POST['mcp_rotate_token']);

            $currentToken = $settings->getString('mcp.bearer_token');
            if ($rotate || ($mcpEnabled && $currentToken === '')) {
                $generatedMcpToken = bin2hex(random_bytes(32));
                $settings->set('mcp.bearer_token', $generatedMcpToken);
            }

            if ($mcpEnabled && $settings->getString('mcp.bearer_token') === '') {
                throw new InvalidArgumentException('MCP bearer token could not be created.');
            }
            if ($claudeEnabled && $settings->getString('claude.api_key') === '' && $apiKey === '') {
                throw new InvalidArgumentException('Claude API key is required to activate Claude connectivity.');
            }

            $settings->set('mcp.enabled', $mcpEnabled);
            $settings->set('claude.enabled', $claudeEnabled);
            $settings->set('mcp.server_name', $serverName !== '' ? $serverName : 'tagom-crm');
            $settings->set('claude.model', $model !== '' ? $model : 'claude-sonnet-4-5');
            $saveOptional($settings, 'claude.api_key', $apiKey);

            audit_log('mcp_configured', 'integrations', 1, [
                'mcp_enabled' => $mcpEnabled,
                'claude_enabled' => $claudeEnabled,
                'token_rotated' => $rotate || $generatedMcpToken !== null,
            ]);

            if ($generatedMcpToken !== null) {
                $_SESSION['mcp_token_once'] = $generatedMcpToken;
                header("Location: {$base}/integrations.php?ok=mcp_token");
                exit;
            }

            header("Location: {$base}/integrations.php?ok=mcp");
            exit;
        }

        if ($action === 'test_whatsapp') {
            $testResults['whatsapp'] = $whatsApp->testConnection();
        } elseif ($action === 'test_mcp') {
            $testResults['mcp'] = $mcp->testProtocol();
        } elseif ($action === 'test_claude') {
            $testResults['claude'] = $mcp->testClaude(app_public_url('/mcp/index.php'));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['ok'])) {
    $success = match ((string)$_GET['ok']) {
        'whatsapp' => 'WhatsApp API settings saved. Use Test connection to verify the Cloud API.',
        'mcp' => 'MCP / Claude settings saved.',
        'mcp_token' => 'MCP / Claude settings saved. Copy the new bearer token now; it will not be shown again.',
        default => 'Settings saved.',
    };
}

if (!empty($_SESSION['mcp_token_once'])) {
    $generatedMcpToken = (string)$_SESSION['mcp_token_once'];
    unset($_SESSION['mcp_token_once']);
}

$csrf = CSRF::token();
$webhookUrl = app_public_url('/webhooks/whatsapp.php');
$mcpUrl = app_public_url('/mcp/index.php');
$desktopConfig = [
    'mcpServers' => [
        $settings->getString('mcp.server_name', 'tagom-crm') => [
            'url' => $mcpUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . ($generatedMcpToken ?: '<MCP_BEARER_TOKEN>'),
            ],
        ],
    ],
];

require __DIR__ . '/../app/views/partials/header.php';

$statusBadge = static function (bool $ready, bool $enabled): string {
    if ($ready) {
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    }
    if ($enabled) {
        return 'bg-amber-50 text-amber-700 border-amber-200';
    }
    return 'bg-slate-50 text-slate-600 border-slate-200';
};
?>

<?php if ($error): ?>
  <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($generatedMcpToken): ?>
  <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
    <div class="font-extrabold mb-1">New MCP bearer token</div>
    <code class="block break-all text-sm"><?= h($generatedMcpToken) ?></code>
    <div class="text-xs mt-2">Store this token in Claude Desktop / Claude API. It is stored encrypted and will not be displayed again.</div>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-start justify-between gap-3 mb-4">
      <div>
        <h3 class="font-extrabold text-lg">WhatsApp Cloud API</h3>
        <div class="text-sm text-slate-500">Meta Graph API send + webhook receive</div>
      </div>
      <span class="inline-flex items-center rounded-xl border px-2 py-1 text-xs font-semibold <?= $statusBadge($whatsApp->isReady(), $whatsApp->isEnabled()) ?>">
        <?= $whatsApp->isReady() ? 'Active' : ($whatsApp->isEnabled() ? 'Needs credentials' : 'Off') ?>
      </span>
    </div>

    <form method="post" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="whatsapp_enabled" value="1" <?= $whatsApp->isEnabled() ? 'checked' : '' ?>>
        Activate WhatsApp API
      </label>

      <div>
        <label class="block text-sm text-slate-600 mb-1">Access token <?= $settings->getString('whatsapp.access_token') !== '' ? '(' . h($settings->mask('whatsapp.access_token')) . ')' : '' ?></label>
        <input name="whatsapp_access_token" type="password" placeholder="Leave blank to keep current"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">Phone number ID</label>
          <input name="whatsapp_phone_number_id" value="<?= h($settings->getString('whatsapp.phone_number_id')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">Business account ID</label>
          <input name="whatsapp_business_account_id" value="<?= h($settings->getString('whatsapp.business_account_id')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">Webhook verify token</label>
          <input name="whatsapp_verify_token" value="<?= h($settings->getString('whatsapp.verify_token')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">App secret <?= $settings->getString('whatsapp.app_secret') !== '' ? '(' . h($settings->mask('whatsapp.app_secret')) . ')' : '' ?></label>
          <input name="whatsapp_app_secret" type="password" placeholder="Leave blank to keep current"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">Graph API version</label>
          <input name="whatsapp_api_version" value="<?= h($settings->getString('whatsapp.api_version', 'v21.0')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">Default country code</label>
          <input name="whatsapp_default_country_code" value="<?= h($settings->getString('whatsapp.default_country_code', '20')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>

      <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600">
        <div class="font-semibold mb-1">Webhook URL</div>
        <code class="break-all"><?= h($webhookUrl) ?></code>
        <div class="mt-1">Subscribe to <b>messages</b> in Meta Developer → WhatsApp → Configuration.</div>
      </div>

      <div class="flex gap-2">
        <button type="submit" name="action" value="save_whatsapp"
                class="rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2">Save &amp; activate</button>
        <button type="submit" name="action" value="test_whatsapp"
                class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">Test connection</button>
      </div>
    </form>

    <?php if (!empty($testResults['whatsapp'])): ?>
      <div class="mt-3 rounded-xl border p-3 text-sm <?= $testResults['whatsapp']['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
        <?= h($testResults['whatsapp']['message']) ?>
        <?php if (!empty($testResults['whatsapp']['details'])): ?>
          <pre class="mt-2 text-xs whitespace-pre-wrap"><?= h(json_encode($testResults['whatsapp']['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-start justify-between gap-3 mb-4">
      <div>
        <h3 class="font-extrabold text-lg">Claude MCP module</h3>
        <div class="text-sm text-slate-500">Bearer-authenticated MCP server + Claude API connector</div>
      </div>
      <span class="inline-flex items-center rounded-xl border px-2 py-1 text-xs font-semibold <?= $statusBadge($mcp->isReady(), $mcp->isEnabled()) ?>">
        <?= $mcp->isReady() ? 'Active' : ($mcp->isEnabled() ? 'Needs token' : 'Off') ?>
      </span>
    </div>

    <form method="post" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="mcp_enabled" value="1" <?= $mcp->isEnabled() ? 'checked' : '' ?>>
        Activate internal MCP server
      </label>
      <label class="flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" name="claude_enabled" value="1" <?= $mcp->claudeEnabled() ? 'checked' : '' ?>>
        Activate Claude API connector
      </label>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm text-slate-600 mb-1">MCP server name</label>
          <input name="mcp_server_name" value="<?= h($settings->getString('mcp.server_name', 'tagom-crm')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
        <div>
          <label class="block text-sm text-slate-600 mb-1">Claude model</label>
          <input name="claude_model" value="<?= h($settings->getString('claude.model', 'claude-sonnet-4-5')) ?>"
                 class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
        </div>
      </div>
      <div>
        <label class="block text-sm text-slate-600 mb-1">Claude API key <?= $settings->getString('claude.api_key') !== '' ? '(' . h($settings->mask('claude.api_key')) . ')' : '' ?></label>
        <input name="claude_api_key" type="password" placeholder="Leave blank to keep current"
               class="w-full rounded-xl border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-200">
      </div>
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="mcp_rotate_token" value="1">
        Generate / rotate MCP bearer token
      </label>
      <?php if ($settings->getString('mcp.bearer_token') !== ''): ?>
        <div class="text-xs text-slate-500">Current token on file: <?= h($settings->mask('mcp.bearer_token')) ?></div>
      <?php endif; ?>

      <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600">
        <div class="font-semibold mb-1">MCP endpoint</div>
        <code class="break-all"><?= h($mcpUrl) ?></code>
        <div class="mt-2 font-semibold">Claude Desktop snippet</div>
        <pre class="mt-1 whitespace-pre-wrap break-all"><?= h(json_encode($desktopConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      </div>

      <div class="flex flex-wrap gap-2">
        <button type="submit" name="action" value="save_mcp"
                class="rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-semibold px-4 py-2">Save &amp; activate</button>
        <button type="submit" name="action" value="test_mcp"
                class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">Test MCP</button>
        <button type="submit" name="action" value="test_claude"
                class="rounded-xl border border-slate-200 px-4 py-2 hover:bg-slate-50 font-semibold">Test Claude</button>
      </div>
    </form>

    <?php foreach (['mcp' => 'MCP', 'claude' => 'Claude'] as $key => $label): ?>
      <?php if (!empty($testResults[$key])): ?>
        <div class="mt-3 rounded-xl border p-3 text-sm <?= $testResults[$key]['ok'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
          <b><?= h($label) ?>:</b> <?= h($testResults[$key]['message']) ?>
          <?php if (!empty($testResults[$key]['details'])): ?>
            <pre class="mt-2 text-xs whitespace-pre-wrap"><?= h(json_encode($testResults[$key]['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
