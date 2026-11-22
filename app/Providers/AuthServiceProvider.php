<?php

namespace App\Providers;

use App\Models\Notificacion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Quién puede «Abrir y notificarse» una carta de SUNAFIL.
         *
         * Abrir la carta registra el acuse de recibo y arranca el plazo legal
         * de respuesta, así que no puede hacerlo cualquiera: sólo el
         * administrador de la empresa (o el super admin) y confirmándolo.
         */
        Gate::define('abrir-notificacion-sunafil', function ($user, Notificacion $notificacion) {
            if (! $user || (int) $user->company_id !== (int) $notificacion->company_id) {
                return false;
            }

            return method_exists($user, 'hasAnyRole')
                && $user->hasAnyRole(['super_admin', 'company_admin']);
        });
    }
}
