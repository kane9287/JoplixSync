<?php
namespace OCA\Joplix\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\App\IAppManager;
use OCP\IConfig;

class JoplixDevUpdateService {
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
        $tmpZip = $this->writeTemp($file->getContent(), 'joplix_update_', '.zip');
        $tmpDir = sys_get_temp_dir() . '/joplix_update_' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Temp-Verzeichnis konnte nicht erstellt werden');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new \RuntimeException('ZIP kann nicht geoeffnet werden');
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        $source = $this->detectPackageSource($tmpDir);
        if ($source === '') {
            $this->cleanup($tmpZip, $tmpDir);
            throw new \RuntimeException('ZIP enthaelt weder joplix/appinfo/info.xml noch appinfo/info.xml im ZIP-Root');
        }
        $info = $source . '/appinfo/info.xml';
        if (!is_file($info)) {
            $this->cleanup($tmpZip, $tmpDir);
            throw new \RuntimeException('ZIP enthaelt keine appinfo/info.xml');
        }
        $meta = $this->readAppInfo($info);
        if (($meta['id'] ?? '') !== 'joplix') {
            $this->cleanup($tmpZip, $tmpDir);
            throw new \RuntimeException('ZIP ist kein gueltiges Joplix-Paket: appinfo/info.xml enthaelt keine Joplix-ID');
        }
        $newVersion = (string)($meta['version'] ?? '');
        $appDir = realpath(__DIR__ . '/../../');
        if ($appDir === false || basename($appDir) !== 'joplix') {
            $this->cleanup($tmpZip, $tmpDir);
            throw new \RuntimeException('Aktuelles App-Verzeichnis konnte nicht bestimmt werden');
        }
        if (!is_writable($appDir) || !is_writable(dirname($appDir))) {
            $this->cleanup($tmpZip, $tmpDir);
            throw new \RuntimeException('App-Verzeichnis ist fuer den Webserver nicht schreibbar. Bitte CLI-Installer verwenden.');
        }
        $appsDir = dirname($appDir);
        $backupRoot = $this->backupEnabled ? $this->backupRoot() : '';
        $this->cleanupLegacyAppBackups($appsDir, $backupRoot);
        $backup = '';
        if ($this->backupEnabled) {
            $backup = $backupRoot . '/joplix.webupdate_' . date('Ymd_His');
            $this->recursiveCopy($appDir, $backup);
            $this->cleanupBackups($backupRoot);
        }
        $preserve = ['.joplix_keep'];
        $this->recursiveClear($appDir, $preserve);
        $this->recursiveCopy($source, $appDir);
        // A previous updater version may have left app-like backup directories behind.
        // Remove or archive them again after replacing the application so Nextcloud never
        // attempts to load them as separate apps during the following upgrade/repair run.
        $this->cleanupLegacyAppBackups($appsDir, $backupRoot);
        // Replacing the files changes info.xml immediately, but Nextcloud still
        // keeps the previous installed_version in app config. If that mismatch
        // survives this request, the next page load opens the global Nextcloud
        // app-upgrade screen and runs repair steps for the whole installation.
        // Finish the same app-specific upgrade lifecycle that Nextcloud's own
        // installer uses after it has replaced an app's files.
        $this->completeNextcloudAppUpgrade($newVersion);
        $this->cleanup($tmpZip, $tmpDir);
        return [
            'ok' => true,
            'installed' => $name,
            'version' => $newVersion,
            'backup' => $backup,
            'nextcloud_upgrade_completed' => true,
            'message' => $this->backupEnabled ? 'Update installiert, Joplix-Aktualisierung abgeschlossen und App-Backup erstellt. Bitte Seite neu laden.' : 'Update installiert und Joplix-Aktualisierung abgeschlossen. Es wurde entsprechend der Einstellung kein App-Backup erstellt. Bitte Seite neu laden.',
        ];
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

    private function cleanupLegacyAppBackups(string $appsDir, string $backupRoot = ''): void {
        $entries = @scandir($appsDir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Historical updater versions used names such as:
            // joplixbackup20260801_181413, joplixwebupdate_...,
            // joplix.webupdate_... and joplix_backup_....
            if (!preg_match('/^joplix(?:backup|webupdate|[._-](?:backup|webupdate)).*/i', $entry)) {
                continue;
            }
            $source = $appsDir . '/' . $entry;
            if (!is_dir($source)) {
                continue;
            }
            if (!$this->backupEnabled || $backupRoot === '') {
                $this->recursiveDelete($source);
                continue;
            }
            $target = $backupRoot . '/' . $entry;
            if (file_exists($target)) {
                $target .= '_' . date('Ymd_His');
            }
            if (!@rename($source, $target)) {
                $this->recursiveCopy($source, $target);
                $this->recursiveDelete($source);
            }
        }
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
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveDelete($path . '/' . $item);
            }
        }
        @rmdir($path);
    }

    public function remoteReleases(string $repoUrl, string $channel, string $installedVersion = ''): array {
        $repo = $this->normalizeRepo($repoUrl);
        $channel = strtolower($channel) === 'beta' ? 'beta' : 'main';
        $api = 'https://codeberg.org/api/v1/repos/' . rawurlencode($repo['owner']) . '/' . rawurlencode($repo['repo']) . '/releases';
        $json = $this->httpGet($api, 5 * 1024 * 1024);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Codeberg Antwort konnte nicht gelesen werden');
        }
        $out = [];
        foreach ($data as $rel) {
            if (!is_array($rel) || !empty($rel['draft'])) continue;
            $tag = (string)($rel['tag_name'] ?? $rel['tag'] ?? '');
            $name = (string)($rel['name'] ?? $tag);
            $isBeta = !empty($rel['prerelease']) || preg_match('/(beta|alpha|rc|pre|dev)/i', $tag . ' ' . $name);
            if ($channel === 'main' && $isBeta) continue;
            if ($channel === 'beta' && !$isBeta) continue;
            $assets = [];
            foreach (($rel['assets'] ?? []) as $a) {
                if (!is_array($a)) continue;
                $an = (string)($a['name'] ?? '');
                $url = (string)($a['browser_download_url'] ?? $a['download_url'] ?? '');
                if ($an === '' || $url === '') continue;
                if (!preg_match('/^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i', $an)) continue;
                if (preg_match('/install_bundle/i', $an)) continue;
                $assets[] = ['name'=>$an,'url'=>$url,'size'=>(int)($a['size'] ?? 0)];
            }
            if (!$assets) continue;
            $relVersion = $this->releaseVersion($tag, $name);
            if ($relVersion === '') continue;
            $out[] = [
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
        return ['source'=>'codeberg','repo_url'=>$repo['url'],'api_url'=>$api,'channel'=>$channel,'installed_version'=>$installedVersion,'releases'=>$out];
    }

    public function installRemoteAsset(string $assetUrl, string $assetName): array {
        $this->assertCodebergAsset($assetUrl);
        if (!preg_match('/^JoplixNC_v\d+\.\d+\.\d+(?:-beta)?\.zip$/i', $assetName) || preg_match('/install_bundle/i', $assetName)) {
            throw new \RuntimeException('Ungueltiger Release-Dateiname');
        }
        $content = $this->httpGet($assetUrl, 35 * 1024 * 1024);
        if (strlen($content) < 1000) {
            throw new \RuntimeException('Download ist zu klein oder unvollstaendig');
        }
        $folder = $this->devFolder();
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $assetName);
        // Keep the canonical Codeberg asset name. installPackage()/packageFile() deliberately
        // validates that same public JoplixNC_v<version>[-beta].zip schema. Beta 59
        // appended _remote_<timestamp> here, making a valid downloaded release fail the
        // second validation step with "Ungueltiger Paketname".
        $name = $safe;
        if ($folder->nodeExists($name)) {
            $folder->get($name)->delete();
        }
        $folder->newFile($name, $content);
        $result = $this->installPackage($name);
        $result['downloaded_as'] = $name;
        $result['source'] = 'codeberg';
        return $result;
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

    private function normalizeRepo(string $repoUrl): array {
        $repoUrl = trim($repoUrl) !== '' ? trim($repoUrl) : 'https://codeberg.org/Stoneubi/joplix/releases';
        $repoUrl = html_entity_decode($repoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/href=["\']([^"\']+)["\']/i', $repoUrl, $hm)) {
            $repoUrl = $hm[1];
        }
        $repoUrl = preg_replace('/\s+/', '', trim($repoUrl));
        $repoUrl = preg_replace('#^http://#i', 'https://', $repoUrl);
        if (preg_match('#^codeberg\.(?:org|com)/#i', $repoUrl)) {
            $repoUrl = 'https://' . $repoUrl;
        }
        $repoUrl = preg_replace('#^https://www\.codeberg\.#i', 'https://codeberg.', $repoUrl);
        $parts = parse_url($repoUrl);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''), '/');
        if (!in_array($host, ['codeberg.com','codeberg.org'], true) || $path === '') {
            throw new \RuntimeException('Nur Codeberg-Repositories werden unterstuetzt. Erwartet wird z.B. https://codeberg.org/Stoneubi/joplix/releases');
        }
        $segments = array_values(array_filter(explode('/', $path), fn($v) => $v !== ''));
        if (count($segments) < 2) {
            throw new \RuntimeException('Codeberg-Repository unvollstaendig. Erwartet wird Besitzer/Repository, z.B. Stoneubi/joplix');
        }
        $owner = rawurldecode($segments[0]);
        $repo = rawurldecode(preg_replace('/\.git$/i', '', $segments[1]));
        if (!preg_match('/^[a-z0-9_.-]+$/i', $owner) || !preg_match('/^[a-z0-9_.-]+$/i', $repo)) {
            throw new \RuntimeException('Codeberg-Repository enthaelt ungueltige Zeichen');
        }
        return ['owner'=>$owner,'repo'=>$repo,'url'=>'https://codeberg.org/'.$owner.'/'.$repo.'/releases'];
    }

    private function assertCodebergAsset(string $url): void {
        if (!preg_match('#^https://codeberg\.(?:org|com)/#i', $url)) {
            throw new \RuntimeException('Nur Codeberg-Download-URLs werden unterstuetzt');
        }
    }

    private function httpGet(string $url, int $maxBytes): string {
        $ctx = stream_context_create(['http'=>[
            'method'=>'GET',
            'timeout'=>30,
            'ignore_errors'=>true,
            'header'=>"Accept: application/json, application/octet-stream\r\nUser-Agent: Joplix-Updater\r\n",
        ]]);
        $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException('Download von Codeberg fehlgeschlagen: ' . $url);
        }
        if (strlen($data) > $maxBytes) {
            throw new \RuntimeException('Download ist groesser als erlaubt');
        }
        if (isset($http_response_header) && is_array($http_response_header)) {
            $line = $http_response_header[0] ?? '';
            if (preg_match('/\s(4\d\d|5\d\d)\s/', $line)) {
                throw new \RuntimeException('Codeberg meldet Fehler: ' . $line);
            }
        }
        return $data;
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
        rename($tmp, $path);
        file_put_contents($path, $content);
        return $path;
    }

    private function recursiveCopy(string $src, string $dst): void {
        if (is_dir($src)) {
            if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
                throw new \RuntimeException('Verzeichnis konnte nicht erstellt werden: ' . $dst);
            }
            $items = scandir($src);
            if ($items === false) return;
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $this->recursiveCopy($src . '/' . $item, $dst . '/' . $item);
            }
        } else {
            copy($src, $dst);
        }
    }

    private function recursiveClear(string $dir, array $preserveNames = []): void {
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, $preserveNames, true)) continue;
            $path = $dir . '/' . $item;
            $this->recursiveRemove($path);
        }
    }

    private function recursiveRemove(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            $items = scandir($path);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') $this->recursiveRemove($path . '/' . $item);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    private function cleanup(string $zip, string $dir): void {
        if (is_file($zip)) @unlink($zip);
        if (is_dir($dir)) $this->recursiveRemove($dir);
    }
}
