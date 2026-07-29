<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class GeneratePermissionsFromRoutes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:generate
                            {--group= : Group name for permissions}
                            {--prefix= : Prefix to filter routes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate permissions from application routes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating permissions from routes...');

        $routes = Route::getRoutes();
        $configuredGroup = $this->option('group');
        $prefix = $this->option('prefix');
        $created = 0;
        $skipped = 0;
        $renamed = 0;

        foreach ($routes as $route) {
            $group = $configuredGroup;

            // Skip if route has no name
            if (!$route->getName()) {
                continue;
            }

            // Skip if prefix is provided and route doesn't match
            if ($prefix && !str_starts_with($route->getName(), $prefix)) {
                continue;
            }

            // Skip only internal Filament system routes, but include resource routes
            $routeName = $route->getName();

            // Skip internal Filament routes (auth, dashboard, etc.)
            $skipPatterns = [
                'filament.admin.auth.',
                'filament.admin.pages.dashboard',
                'filament.admin.widgets.',
                'livewire.',
                'livewire.message',
                'livewire.upload-file',
                'livewire.download-file',
            ];

            $shouldSkip = false;
            foreach ($skipPatterns as $pattern) {
                if (str_contains($routeName, $pattern)) {
                    $shouldSkip = true;
                    break;
                }
            }

            if ($shouldSkip) {
                continue;
            }

            $originalRouteName = $route->getName();
            $permissionName = $this->formatPermissionName($originalRouteName);

            // Determine permission group if not explicitly provided
            if (!$group) {
                $group = $this->determineGroupFromPermissionName($permissionName);
            }

            $permission = Permission::where('name', $permissionName)->first();

            if (!$permission) {
                // Attempt to rename legacy permission entries (old Filament naming)
                if ($permissionName !== $originalRouteName) {
                    $legacyPermission = Permission::where('name', $originalRouteName)->first();
                    if ($legacyPermission) {
                        $legacyPermission->name = $permissionName;
                        $legacyPermission->save();
                        $renamed++;
                        $this->line("Renamed permission: {$originalRouteName} -> {$permissionName} (Group: {$group})");

                        // Ensure Super Admin has the renamed permission
                        $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'Super Admin')->first();
                        if ($superAdminRole && !$superAdminRole->hasPermissionTo($permissionName)) {
                            $superAdminRole->givePermissionTo($permissionName);
                        }
                        continue;
                    }
                }

                Permission::create([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
                $created++;
                $this->line("Created permission: {$permissionName} (Group: {$group})");

                // Automatically assign new permissions to Super Admin role
                $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'Super Admin')->first();
                if ($superAdminRole) {
                    $superAdminRole->givePermissionTo($permissionName);
                }
            } else {
                $skipped++;
            }

            // For Filament resource routes, also create delete permission if it doesn't exist
            // users.index, users.create, users.edit -> also create users.delete
            if (str_starts_with($originalRouteName, 'filament.admin.resources.')) {
                $parts = explode('.', $originalRouteName);
                if (count($parts) >= 5 && $parts[2] === 'resources') {
                    $resourceName = $parts[3];
                    $action = $parts[4] ?? null;

                    // Only create delete permission for index, create, or edit routes
                    if (in_array($action, ['index', 'create', 'edit'])) {
                        $deletePermissionName = "{$resourceName}.delete";
                        $deletePermission = Permission::where('name', $deletePermissionName)->first();

                        if (!$deletePermission) {
                            Permission::create([
                                'name' => $deletePermissionName,
                                'guard_name' => 'web',
                            ]);
                            $created++;
                            $deleteGroup = $this->determineGroupFromPermissionName($deletePermissionName);
                            $this->line("Created permission: {$deletePermissionName} (Group: {$deleteGroup})");

                            // Automatically assign new permissions to Super Admin role
                            $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'Super Admin')->first();
                            if ($superAdminRole) {
                                $superAdminRole->givePermissionTo($deletePermissionName);
                            }
                        }
                    }
                }
            }
        }

        // Create dashboard widget permissions
        $dashboardPermissions = [
            'dashboard.view.stats_cards' => 'Dashboard - View Stats Cards',
            'dashboard.view.member_growth' => 'Dashboard - View Member Growth',
            'dashboard.view.payment_trends' => 'Dashboard - View Payment Trends',
            'dashboard.view.latest_payments' => 'Dashboard - View Latest Payments',
        ];

        foreach ($dashboardPermissions as $permissionName => $description) {
            $permission = Permission::where('name', $permissionName)->first();

            if (!$permission) {
                Permission::create([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
                $created++;
                $this->line("Created permission: {$permissionName} (Group: Dashboard)");

                // Automatically assign new permissions to Super Admin role
                $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'Super Admin')->first();
                if ($superAdminRole) {
                    $superAdminRole->givePermissionTo($permissionName);
                }
            } else {
                $skipped++;
            }
        }

        $this->info("Permissions generation completed!");
        $this->info("Created: {$created} permission(s)");
        $this->info("Renamed: {$renamed} permission(s)");
        $this->info("Skipped: {$skipped} existing permission(s)");

        return Command::SUCCESS;
    }

    protected function formatPermissionName(string $routeName): string
    {
        if (! str_starts_with($routeName, 'filament.')) {
            return $routeName;
        }

        $parts = explode('.', $routeName);

        // Handle Filament resource routes, e.g. filament.admin.resources.users.edit -> users.edit
        if (
            count($parts) >= 5 &&
            $parts[0] === 'filament' &&
            ($parts[2] ?? null) === 'resources'
        ) {
            $resourceName = $parts[3];
            $action = $parts[4] ?? 'index';

            return "{$resourceName}.{$action}";
        }

        // For other Filament routes (exports, imports, etc.), remove the first segment
        return implode('.', array_slice($parts, 1));
    }

    protected function determineGroupFromPermissionName(string $permissionName): string
    {
        $prefix = Str::before($permissionName, '.');

        if (empty($prefix)) {
            return 'General';
        }

        return Str::of($prefix)
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->squish()
            ->title()
            ->value();
    }
}
