<?php
namespace OCA\Joplix\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\App\IAppManager;
use OCP\IConfig;

class JoplixDevUpdateService {
    private const MAX_ZIP_BYTES = 35 * 1024 * 1024;
    // Update source: GitHub releases of this repository. The admin can point the updater at another
    // GitHub repository (a fork) in the settings; other hosts are rejected by normalizeRepo().
    public const DEFAULT_REPO_URL = 'https://github.com/kane9287/joplix-xsync/releases';
    // Present in a source checkout but not part of the installed app. Removed from every package before
    // activation so a GitHub source archive installs the same files as a packaged JoplixNC_v<version>.zip.
    private const NON_APP_ENTRIES = ['tools', 'docs', 'install', 'assets', '.github', '.gitignore', '.gitattributes'];

    private Folder $userFolder;
    private string $devPath;
    private IConfig $config;
    private bool $backupEnabled;
    private ?IAppManager $appManager;

    public function __construct(Folder $userFolder, string $devPath, ?IConfig $config = null, bool $backupEnabled = false, ?IAppManager $appManager = null) {
        $this->userFolder = $userFolder;
        $this->devPath = trim($devPath) !== '' ? trim($devPath) : '/.joplix/updates';
        // Keep the service compatible with older controllers that still pass only
        // userFolder and devPath during an in-place web update.
        $this->config = $config ?? \OCP\Server::get(IConfig::class);
        $this->backupEnabled = $backupEnabled;
        $this->appManager = $appManager;
    }

    public function listPackages(): array {
        $folder = $this->devFolder();
        $packages = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if (!$node instanceof File) {
                continue;
            }
            $name = $node->getName();
            if (!preg_match('/^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i', $name)) {
                continue;
            }
            $meta = $this->inspectPackage($node);
            $packages[] = [
                'name' => $name,
                'size' => $node->getSize(),
                'mtime' => $node->getMTime(),
                'version' => $meta['version'] ?? '',
                'valid' => $meta['valid'] ?? false,
                'message' => $meta['message'] ?? '',
            ];
        }
        usort($packages, fn($a, $b) => strcmp((string)$b['mtime'], (string)$a['mtime']));
        return $packages;
    }

    public function deletePackage(string $name): array {
        $file = $this->packageFile($name);
        $file->delete();
        return ['ok' => true, 'deleted' => $name];
    }

    public function installPackage(string $name): array {
        $file = $this->packageFile($name);
        if ($file->getSize() > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('ZIP ist zu gross.');
        }
        return $this->installArchive($file->getContent(), $name);
    }

    public function installedVersion(): string {
        clearstatcache(true, __DIR__ . '/../../appinfo/info.xml');
        return (string)($this->readAppInfo(__DIR__ . '/../../appinfo/info.xml')['version'] ?? '');
    }

    private function installArchive(string $content, string $name, ?string $releaseVersion = null): array {
        if ($content === '' || strlen($content) > self::MAX_ZIP_BYTES) {
            throw new \RuntimeException('ZIP ist leer oder zu gross.');
        }
        $appDir = realpath(__DIR__ . '/../../');
        if ($appDir === false || basename($appDir) !== 'joplix' || !is_writable(dirname($appDir))) {
            throw new \RuntimeException('App-Verzeichnis kann nicht ersetzt werden. Bitte CLI-Installer verwenden.');
        }
        $work = dirname($appDir) . '/.joplix-update';
        if (is_link($work) || (!is_dir($work) && !@mkdir($work, 0700) && !is_dir($work))) {
            throw new \RuntimeException('Update-Verzeichnis konnte nicht erstellt werden.');
        }
        $lock = @fopen($work . '/lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Update-Sperre konnte nicht geoeffnet werden.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Ein Joplix-Update laeuft bereits.');
            }
            return $this->replaceApp($appDir, $work, $content, $name, $releaseVersion);
        } finally {
            fclose($lock);
        }
    }

    private function replaceApp(string $appDir, string $work, string $content, string $name, ?string $releaseVersion): array {
        $previous = $work . '/previous';
        if (file_exists($previous)) {
            throw new \RuntimeException('Vorheriges Update wurde unterbrochen. Wiederherstellung erforderlich: ' . $previous);
        }
        $stage = $work . '/stage-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0700)) {
            throw new \RuntimeException('Update konnte nicht vorbereitet werden.');
        }
        $backup = '';
        try {
            $archive = $stage . '/package.zip';
            if (file_put_contents($archive, $content) !== strlen($content)) {
                throw new \RuntimeException('ZIP konnte nicht vollstaendig gespeichert werden.');
            }
            $extracted = $stage . '/extracted';
            if (!mkdir($extracted, 0750)) {
                throw new \RuntimeException('Entpackverzeichnis konnte nicht erstellt werden.');
            }
            $this->extractArchive($archive, $extracted);
            $source = $this->detectPackageSource($extracted);
            $version = $this->validateApp($source);
            foreach (self::NON_APP_ENTRIES as $entry) {
                $this->recursiveDelete($source . '/' . $entry);
            }
            if ($releaseVersion !== null) {
                $installed = $this->parseVersion($this->installedVersion());
                $candidate = $this->parseVersion($version);
                if ($version !== $releaseVersion || $installed === null || $candidate === null
                    || $this->compareVersions($candidate, $installed) <= 0) {
                    throw new \RuntimeException('ZIP-Version passt nicht zum Release oder ist bereits installiert.');
                }
            }
            if (is_file($appDir . '/.joplix_keep') && !file_exists($source . '/.joplix_keep')
                && !copy($appDir . '/.joplix_keep', $source . '/.joplix_keep')) {
                throw new \RuntimeException('Lokale Markierungsdatei konnte nicht uebernommen werden.');
            }
            if ($this->backupEnabled) {
                $backup = $this->backupRoot() . '/joplix.webupdate_' . date('Ymd_His') . '_' . basename($stage);
                try {
                    $this->recursiveCopy($appDir, $backup);
                } catch (\Throwable $error) {
                    $this->recursiveDelete($backup);
                    throw $error;
                }
            }
            if (!@rename($appDir, $previous)) {
                throw new \RuntimeException('Bisherige App konnte nicht fuer die Wiederherstellung gesichert werden.');
            }

            $activated = false;
            try {
                if (!@rename($source, $appDir)) {
                    throw new \RuntimeException('Vorbereitete App konnte nicht aktiviert werden.');
                }
                $activated = true;
                clearstatcache(true);
                $this->completeNextcloudAppUpgrade($version);
            } catch (\Throwable $error) {
                if (($activated && !@rename($appDir, $stage . '/failed')) || !@rename($previous, $appDir)) {
                    throw new \RuntimeException('Automatische Wiederherstellung fehlgeschlagen. Alte App-Dateien: ' . $previous . '. ' . $error->getMessage(), 0, $error);
                }
                clearstatcache(true);
                $databaseWarning = $activated ? ' Nextcloud-Datenbank und App-Version auf teilweise ausgefuehrte Migrationen pruefen.' : '';
                throw new \RuntimeException('Alte App-Dateien wiederhergestellt.' . $databaseWarning . ' ' . $error->getMessage(), 0, $error);
            }

            $this->recursiveDelete($previous);
            if ($backup !== '') {
                $this->cleanupBackups(dirname($backup));
            }
            return [
                'ok' => true,
                'installed' => $name,
                'version' => $version,
                'backup' => $backup,
                'nextcloud_upgrade_completed' => true,
                'message' => 'Joplix-Update abgeschlossen. Bitte Seite neu laden.',
            ];
        } finally {
            $this->recursiveDelete($stage);
        }
    }

    private function extractArchive(string $archive, string $destination): void {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('ZIP kann nicht geoeffnet werden.');
        }
        try {
            $totalSize = 0;
            $seen = [];
            if ($zip->numFiles > 10000) {
                throw new \RuntimeException('ZIP enthaelt zu viele Eintraege.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $path = $entry['name'] ?? '';
                $normalized = rtrim($path, '/');
                if ($normalized === '' || str_starts_with($path, '/') || str_contains($path, '\\')
                    || preg_match('/[\x00-\x1f:]/', $path)
                    || array_intersect(explode('/', $normalized), ['', '.', '..']) || isset($seen[$normalized])) {
                    throw new \RuntimeException('ZIP enthaelt einen unsicheren oder doppelten Pfad.');
                }
                $seen[$normalized] = true;
                if ($zip->getExternalAttributesIndex($index, $os, $attributes) && $os === \ZipArchive::OPSYS_UNIX
                    && !in_array(($attributes >> 16) & 0170000, [0, 0100000, 0040000], true)) {
                    throw new \RuntimeException('ZIP enthaelt Links oder Sonderdateien.');
                }
                $totalSize += $entry['size'];
                if ($totalSize > 200 * 1024 * 1024) {
                    throw new \RuntimeException('Entpacktes ZIP ist zu gross.');
                }
            }
            if (!$zip->extractTo($destination)) {
                throw new \RuntimeException('ZIP konnte nicht vollstaendig entpackt werden.');
            }
        } finally {
            $zip->close();
        }
    }

    private function validateApp(string $source): string {
        $info = $source === '' ? false : @simplexml_load_file($source . '/appinfo/info.xml', \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($info === false || (string)$info->id !== 'joplix' || !preg_match('/^\d+\.\d+\.\d+$/D', (string)$info->version)) {
            throw new \RuntimeException('ZIP enthaelt keine gueltige Joplix-App.');
        }
        foreach (['appinfo/routes.php', 'lib/AppInfo/Application.php', 'js/app.js', 'templates/main.php'] as $required) {
            if (!is_file($source . '/' . $required)) {
                throw new \RuntimeException('Unvollstaendiges Joplix-Paket: ' . $required);
            }
        }
        $nextcloud = $info->dependencies->nextcloud;
        $serverMajor = \OCP\Util::getVersion()[0];
        $min = (int)$nextcloud['min-version'];
        $max = (int)$nextcloud['max-version'];
        if (!$min || !$max || $serverMajor < $min || $serverMajor > $max) {
            throw new \RuntimeException('Dieses Paket ist mit der installierten Nextcloud-Version nicht kompatibel.');
        }
        return (string)$info->version;
    }

    private function completeNextcloudAppUpgrade(string $expectedVersion): void {
        if ($expectedVersion === '') {
            throw new \RuntimeException('Joplix-Version fuer den Nextcloud-App-Abschluss fehlt');
        }
        $manager = $this->appManager ?? \OC::$server->get(IAppManager::class);
        if (!$manager->upgradeApp('joplix')) {
            throw new \RuntimeException('Nextcloud konnte die Joplix-App-Aktualisierung nicht abschliessen');
        }
        $installedVersion = (string)$this->config->getAppValue('joplix', 'installed_version', '');
        if (version_compare($installedVersion, $expectedVersion, '!=')) {
            throw new \RuntimeException('Nextcloud meldet nach dem Joplix-Update Version ' . $installedVersion . ' statt ' . $expectedVersion);
        }
    }

    private function backupRoot(): string {
        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            throw new \RuntimeException('Nextcloud-Datenverzeichnis konnte nicht bestimmt werden');
        }
        $root = $dataDir . '/.joplix/backups';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException('Backup-Verzeichnis konnte nicht erstellt werden: ' . $root);
        }
        if (!is_writable($root)) {
            throw new \RuntimeException('Backup-Verzeichnis ist nicht beschreibbar: ' . $root);
        }
        return $root;
    }

    private function cleanupBackups(string $root): void {
        $items = glob($root . '/joplix.webupdate_*', GLOB_ONLYDIR) ?: [];
        usort($items, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $cutoff = time() - 30 * 86400;
        foreach ($items as $index => $path) {
            if ($index >= 5 || filemtime($path) < $cutoff) $this->recursiveDelete($path);
        }
    }

    private function recursiveDelete(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = @scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveDelete($path . '/' . $item);
            }
        }
        @rmdir($path);
    }

    public function remoteReleases(string $repoUrl, string $channel, string $installedVersion, ?int $releaseId = null): array {
        $repo = self::normalizeRepo($repoUrl);
        $channel = strtolower($channel) === 'beta' ? 'beta' : 'main';
        $api = 'https://api.github.com/repos/' . rawurlencode($repo['owner']) . '/' . rawurlencode($repo['repo']) . '/releases';
        $api .= $releaseId !== null ? '/' . $releaseId : '?per_page=50';
        $json = $this->httpGet($api, 5 * 1024 * 1024);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub-Antwort konnte nicht gelesen werden');
        }
        if ($releaseId !== null) {
            $data = [$data];
        }
        $out = [];
        foreach ($data as $rel) {
            if (!is_array($rel) || !empty($rel['draft']) || (int)($rel['id'] ?? 0) < 1) continue;
            $tag = (string)($rel['tag_name'] ?? $rel['tag'] ?? '');
            $name = (string)($rel['name'] ?? $tag);
            // GitHub's pre-release flag selects the beta channel. The name check only matches whole words so
            // that a name such as "Search fix" (contains "rc") is not mistaken for a pre-release.
            $isBeta = !empty($rel['prerelease']) || preg_match('/(?<![a-z])(beta|alpha|rc|pre|dev)(?![a-z])/i', $tag . ' ' . $name);
            if ($channel === 'main' && $isBeta) continue;
            if ($channel === 'beta' && !$isBeta) continue;
            $relVersion = $this->releaseVersion($tag, $name);
            if ($relVersion === '') continue;
            $assets = [];
            foreach (($rel['assets'] ?? []) as $a) {
                if (!is_array($a)) continue;
                $assetId = (int)($a['id'] ?? 0);
                $filename = (string)($a['name'] ?? '');
                $url = (string)($a['browser_download_url'] ?? $a['download_url'] ?? '');
                if ($assetId < 1 || !$this->isReleaseAsset($url, $repo, $tag, $filename)
                    || !preg_match('/^JoplixNC_v(\d+\.\d+\.\d+)(?:-beta)?\.zip$/iD', $filename, $match)
                    || $match[1] !== $relVersion) {
                    continue;
                }
                $assets[] = ['id'=>$assetId, 'name'=>$filename, 'url'=>$url, 'size'=>(int)($a['size'] ?? 0)];
            }
            if (!$assets) {
                // No packaged JoplixNC_v<version>.zip attached: use GitHub's automatic source archive of
                // the tag. It gets a synthetic asset (release id, canonical package name) so the browser
                // and installRemoteAsset() handle it like a packaged asset. Size 0 skips the size check.
                $zipball = (string)($rel['zipball_url'] ?? '');
                if (preg_match('/^\d+\.\d+\.\d+$/D', $relVersion) && $this->isSourceArchive($zipball, $repo, $tag)) {
                    $assets[] = ['id'=>(int)$rel['id'], 'name'=>'JoplixNC_v' . $relVersion . ($isBeta ? '-beta' : '') . '.zip', 'url'=>$zipball, 'size'=>0];
                }
            }
            if (!$assets) continue;
            $out[] = [
                'id'=>(int)$rel['id'],
                'tag'=>$tag,
                'name'=>$name ?: $tag,
                'channel'=>$isBeta ? 'beta' : 'main',
                'version'=>$relVersion,
                'published_at'=>(string)($rel['published_at'] ?? $rel['created_at'] ?? ''),
                'body'=>(string)($rel['body'] ?? ''),
                'html_url'=>(string)($rel['html_url'] ?? $rel['url'] ?? ''),
                'url'=>(string)($rel['html_url'] ?? $rel['url'] ?? ''),
                'assets'=>$assets,
            ];
        }
        $installedParsed = $this->parseVersion($installedVersion);
        if ($installedParsed !== null) {
            $out = array_values(array_filter($out, function($rel) use ($installedParsed) {
                $rv = $this->parseVersion((string)($rel['version'] ?? ''));
                return $rv !== null && $this->compareVersions($rv, $installedParsed) > 0;
            }));
        }
        usort($out, function($a, $b) {
            $av = $this->parseVersion((string)($a['version'] ?? ''));
            $bv = $this->parseVersion((string)($b['version'] ?? ''));
            if ($av === null || $bv === null) return 0;
            return -1 * $this->compareVersions($av, $bv);
        });
        return ['source'=>'github','repo_url'=>$repo['url'],'api_url'=>$api,'channel'=>$channel,'installed_version'=>$installedVersion,'releases'=>$out];
    }

    public function installRemoteAsset(string $repoUrl, string $channel, int $releaseId, int $assetId): array {
        if ($releaseId < 1 || $assetId < 1) {
            throw new \RuntimeException('Bitte ein Update aus der aktuellen Release-Liste auswaehlen.');
        }
        $installed = $this->installedVersion();
        if ($this->parseVersion($installed) === null) {
            throw new \RuntimeException('Installierte Joplix-Version konnte nicht bestimmt werden.');
        }
        $result = $this->remoteReleases($repoUrl, $channel, $installed, $releaseId);
        foreach ($result['releases'] as $release) {
            if ($release['id'] !== $releaseId) continue;
            foreach ($release['assets'] as $asset) {
                if ($asset['id'] !== $assetId) continue;
                $content = $this->httpGet($asset['url'], self::MAX_ZIP_BYTES);
                if ($asset['size'] > 0 && strlen($content) !== $asset['size']) {
                    throw new \RuntimeException('Download-Groesse stimmt nicht mit dem Release ueberein.');
                }
                return $this->installArchive($content, $asset['name'], $release['version']);
            }
        }
        throw new \RuntimeException('Paket wurde in der konfigurierten Updatequelle und im gewaehlten Kanal nicht gefunden.');
    }

    private function detectPackageSource(string $tmpDir): string {
        $candidates = [
            $tmpDir . '/joplix',
            $tmpDir,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/appinfo/info.xml')) {
                return $candidate;
            }
        }
        $items = scandir($tmpDir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $candidate = $tmpDir . '/' . $item;
                if (is_dir($candidate) && is_file($candidate . '/appinfo/info.xml')) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function releaseVersion(string $tag, string $name): string {
        $v = $this->parseVersion($tag);
        if ($v === null) {
            $v = $this->parseVersion($name);
        }
        return $v === null ? '' : $v['text'];
    }

    private function parseVersion(string $text): ?array {
        $text = trim($text);
        if (!preg_match('/(?:^|[^0-9])v?(\d+)\.(\d+)\.(\d+)(?:[-_.]?(?:beta|main|rc|alpha|pre|dev)?[-_.]?(\d+))?/i', $text, $m)) {
            return null;
        }
        return [
            'major' => (int)$m[1],
            'minor' => (int)$m[2],
            'patch' => (int)$m[3],
            'build' => isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0,
            // Keep calendar versions such as 2026.08.50 exactly as published;
            // comparison remains numeric, so legacy SemVer and CalVer coexist.
            'text' => $m[1] . '.' . $m[2] . '.' . $m[3]
                . (isset($m[4]) && $m[4] !== '' ? '.' . $m[4] : ''),
        ];
    }

    private function compareVersions(array $a, array $b): int {
        foreach (['major','minor','patch','build'] as $key) {
            if (($a[$key] ?? 0) > ($b[$key] ?? 0)) return 1;
            if (($a[$key] ?? 0) < ($b[$key] ?? 0)) return -1;
        }
        return 0;
    }

    // For reading a stored setting: never throws, falls back to the default repository.
    public static function repoUrlOrDefault(string $repoUrl): string {
        try {
            return self::normalizeRepo($repoUrl)['url'];
        } catch (\Throwable $e) {
            return self::DEFAULT_REPO_URL;
        }
    }

    public static function normalizeRepo(string $repoUrl): array {
        $repoUrl = trim($repoUrl) !== '' ? trim($repoUrl) : self::DEFAULT_REPO_URL;
        $repoUrl = html_entity_decode($repoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/href=["\']([^"\']+)["\']/i', $repoUrl, $hm)) {
            $repoUrl = $hm[1];
        }
        $repoUrl = preg_replace('/\s+/', '', trim($repoUrl));
        $repoUrl = preg_replace('#^http://#i', 'https://', $repoUrl);
        if (preg_match('#^(?:www\.)?(?:github\.com|codeberg\.(?:org|com))/#i', $repoUrl)) {
            $repoUrl = 'https://' . $repoUrl;
        }
        $parts = parse_url($repoUrl);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (preg_match('/^(?:www\.)?codeberg\.(?:org|com)$/D', $host)) {
            // Installations that still store the former Codeberg update source move to the GitHub repository.
            $parts = parse_url(self::DEFAULT_REPO_URL);
            $host = 'github.com';
        }
        $path = trim((string)($parts['path'] ?? ''), '/');
        if (!in_array($host, ['github.com', 'www.github.com'], true) || $path === '') {
            throw new \RuntimeException('Nur GitHub-Repositories werden unterstuetzt. Erwartet wird z.B. ' . self::DEFAULT_REPO_URL);
        }
        $segments = array_values(array_filter(explode('/', $path), fn($v) => $v !== ''));
        if (count($segments) < 2) {
            throw new \RuntimeException('GitHub-Repository unvollstaendig. Erwartet wird Besitzer/Repository, z.B. kane9287/joplix-xsync');
        }
        $owner = rawurldecode($segments[0]);
        $repo = rawurldecode(preg_replace('/\.git$/i', '', $segments[1]));
        if (!preg_match('/^[a-z0-9_.-]+$/iD', $owner) || !preg_match('/^[a-z0-9_.-]+$/iD', $repo)
            || in_array($owner, ['.', '..'], true) || in_array($repo, ['.', '..'], true)) {
            throw new \RuntimeException('GitHub-Repository enthaelt ungueltige Zeichen');
        }
        return ['owner'=>$owner,'repo'=>$repo,'url'=>'https://github.com/'.$owner.'/'.$repo.'/releases'];
    }

    private function isPlainHttpsUrl(array $parts, string $host): bool {
        return ($parts['scheme'] ?? '') === 'https' && strtolower($parts['host'] ?? '') === $host
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    private function isSafeTag(string $tag): bool {
        return !str_contains($tag, '/') && !str_contains($tag, '\\') && !in_array($tag, ['', '.', '..'], true);
    }

    // https://github.com/<owner>/<repo>/releases/download/<tag>/<name>
    private function isReleaseAsset(string $url, array $repo, string $tag, string $name): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || !$this->isPlainHttpsUrl($parts, 'github.com')) {
            return false;
        }
        $segments = array_map('rawurldecode', explode('/', $parts['path'] ?? ''));
        return count($segments) === 7 && $segments[0] === ''
            && strcasecmp($segments[1], $repo['owner']) === 0 && strcasecmp($segments[2], $repo['repo']) === 0
            && $segments[3] === 'releases' && $segments[4] === 'download'
            && $segments[5] === $tag && $segments[6] === $name && $this->isSafeTag($tag);
    }

    // https://api.github.com/repos/<owner>/<repo>/zipball/<tag>
    private function isSourceArchive(string $url, array $repo, string $tag): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || !$this->isPlainHttpsUrl($parts, 'api.github.com')) {
            return false;
        }
        $segments = array_map('rawurldecode', explode('/', $parts['path'] ?? ''));
        return count($segments) === 6 && $segments[0] === '' && $segments[1] === 'repos'
            && strcasecmp($segments[2], $repo['owner']) === 0 && strcasecmp($segments[3], $repo['repo']) === 0
            && $segments[4] === 'zipball' && $segments[5] === $tag && $this->isSafeTag($tag);
    }

    // GitHub answers downloads with a redirect to its storage hosts, so redirects are followed by hand
    // and every hop must stay on HTTPS and on a GitHub host.
    private function isGitHubDownloadUrl(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        return in_array($host, ['github.com', 'api.github.com', 'codeload.github.com'], true)
            || str_ends_with($host, '.githubusercontent.com');
    }

    private function httpGet(string $url, int $maxBytes): string {
        for ($hop = 0; $hop < 5; $hop++) {
            if (!$this->isGitHubDownloadUrl($url)) {
                throw new \RuntimeException('Unerlaubte Download-Adresse: ' . $url);
            }
            $ctx = stream_context_create(['http'=>[
                'method'=>'GET',
                'timeout'=>30,
                'follow_location'=>0,
                'ignore_errors'=>true,
                'header'=>"Accept: application/vnd.github+json, application/octet-stream, */*\r\nUser-Agent: Joplix-Updater\r\n",
            ]]);
            $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
            $headers = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            $line = (string)($headers[0] ?? '');
            if (preg_match('/^HTTP\/\S+ 30[1278](?:\s|$)/', $line)) {
                $location = '';
                foreach ($headers as $header) {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                }
                if ($location === '') {
                    throw new \RuntimeException('GitHub-Weiterleitung ohne Ziel: ' . $url);
                }
                $url = $location;
                continue;
            }
            if ($line !== '' && !preg_match('/^HTTP\/\S+ 200(?:\s|$)/', $line)) {
                $hint = preg_match('/ (403|429)(?:\s|$)/', $line) ? ' (moeglicherweise GitHub-Anfragelimit erreicht, spaeter erneut versuchen)' : '';
                throw new \RuntimeException('GitHub meldet Fehler: ' . $line . $hint);
            }
            if ($data === false || $data === '') {
                throw new \RuntimeException('Download von GitHub fehlgeschlagen: ' . $url);
            }
            if (strlen($data) > $maxBytes) {
                throw new \RuntimeException('Download ist groesser als erlaubt');
            }
            return $data;
        }
        throw new \RuntimeException('Zu viele Weiterleitungen beim Download von GitHub');
    }

    private function devFolder(): Folder {
        $relative = trim($this->devPath, '/');
        if ($relative === '') {
            return $this->userFolder;
        }
        if (!$this->userFolder->nodeExists($relative)) {
            $this->userFolder->newFolder($relative);
        }
        $node = $this->userFolder->get($relative);
        if (!$node instanceof Folder) {
            throw new \RuntimeException('Entwicklungsverzeichnis ist kein Ordner: ' . $this->devPath);
        }
        return $node;
    }

    private function packageFile(string $name): File {
        if (!preg_match('/^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i', $name)) {
            throw new \RuntimeException('Ungueltiger Paketname');
        }
        $folder = $this->devFolder();
        if (!$folder->nodeExists($name)) {
            throw new \RuntimeException('Paket nicht gefunden: ' . $name);
        }
        $node = $folder->get($name);
        if (!$node instanceof File) {
            throw new \RuntimeException('Paket ist keine Datei');
        }
        return $node;
    }

    private function inspectPackage(File $file): array {
        $tmp = $this->writeTemp($file->getContent(), 'joplix_inspect_', '.zip');
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return ['valid' => false, 'message' => 'ZIP kann nicht geoeffnet werden'];
        }
        $info = $zip->getFromName('joplix/appinfo/info.xml');
        if ($info === false) {
            $info = $zip->getFromName('appinfo/info.xml');
        }
        $zip->close();
        @unlink($tmp);
        if ($info === false) {
            return ['valid' => false, 'message' => 'appinfo/info.xml fehlt'];
        }
        $meta = $this->parseAppInfoXml($info);
        if (($meta['id'] ?? '') !== 'joplix') {
            return ['valid' => false, 'message' => 'Keine gueltige Joplix-App'];
        }
        return ['valid' => true, 'version' => (string)($meta['version'] ?? ''), 'message' => 'OK'];
    }


    private function readAppInfo(string $path): array {
        $txt = @file_get_contents($path);
        if ($txt === false || trim($txt) === '') return [];
        return $this->parseAppInfoXml($txt);
    }

    private function parseAppInfoXml(string $xml): array {
        $out = [];
        foreach (['id','version','name'] as $tag) {
            if (preg_match('#<'.$tag.'>\s*([^<]+?)\s*</'.$tag.'>#i', $xml, $m)) {
                $out[$tag] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
        }
        return $out;
    }

    private function writeTemp(string $content, string $prefix, string $suffix): string {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new \RuntimeException('Temp-Datei konnte nicht erstellt werden');
        }
        $path = $tmp . $suffix;
        if (!@rename($tmp, $path) || file_put_contents($path, $content) !== strlen($content)) {
            @unlink($tmp);
            @unlink($path);
            throw new \RuntimeException('Temp-Datei konnte nicht vollstaendig geschrieben werden.');
        }
        return $path;
    }

    private function recursiveCopy(string $src, string $dst): void {
        if (is_dir($src)) {
            if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
                throw new \RuntimeException('Verzeichnis konnte nicht erstellt werden: ' . $dst);
            }
            $items = scandir($src);
            if ($items === false) throw new \RuntimeException('Backup-Quellordner nicht lesbar: ' . $src);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveCopy($src . '/' . $item, $dst . '/' . $item);
            }
        } elseif (!copy($src, $dst)) {
            throw new \RuntimeException('Backup-Datei konnte nicht geschrieben werden: ' . $dst);
        }
    }

}
