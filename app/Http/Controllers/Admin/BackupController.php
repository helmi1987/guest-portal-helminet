<?php

namespace App\Http\Controllers\Admin;

use App\Backup\BackupManager;
use App\Http\Controllers\Controller;
use App\Models\LocalSetting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Backups (admin only, CONCEPT.md §9): list, create now, download, delete, retention count.
 */
class BackupController extends Controller
{
    public function __construct(private BackupManager $backups) {}

    public function index(): View
    {
        return view('admin.backups', [
            'backups' => $this->backups->all(),
            'keep' => BackupManager::keep(),
            'dir' => $this->backups->dir(),
            'insecure' => $this->backups->insecureDir(),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $backup = $this->backups->create();
        } catch (Throwable $e) {
            Log::error('Backup failed', ['error' => $e->getMessage()]);
            Audit::log('backup.failed', null, ['error' => mb_substr($e->getMessage(), 0, 500)]);

            return back()->withErrors(['backup' => __('portal.backups.failed')]);
        }
        $pruned = $this->backups->prune();
        Audit::log('backup.created', null, ['file' => $backup['name'], 'size' => $backup['size'],
            'skipped' => $backup['skipped'], 'pruned' => $pruned]);

        return back()->with('status', $backup['skipped'] === []
            ? __('portal.backups.created', ['name' => $backup['name']])
            : __('portal.backups.created_skipped', ['name' => $backup['name'], 'files' => implode(', ', $backup['skipped'])]));
    }

    public function download(string $name): BinaryFileResponse
    {
        $path = $this->backups->path($name) ?? abort(404);
        Audit::log('backup.downloaded', null, ['file' => $name]);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store']);
    }

    public function destroy(string $name): RedirectResponse
    {
        abort_unless($this->backups->delete($name), 404);
        Audit::log('backup.deleted', null, ['file' => $name]);

        return back()->with('status', __('portal.deleted'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $keep = (int) $request->validate(['keep' => ['required', 'integer', 'between:1,365']])['keep'];
        LocalSetting::put(BackupManager::KEEP_SETTING, $keep);
        Audit::log('backup.settings', null, ['keep' => $keep]);

        return back()->with('status', __('portal.saved'));
    }
}
