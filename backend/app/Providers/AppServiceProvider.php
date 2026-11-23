<?php

namespace App\Providers;

use App\Services\Ai\Llm\AnthropicLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\Http\MercadoPublicoV2Client;
use App\Support\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un tenant activo por request/job.
        $this->app->scoped(CurrentTenant::class);
        // Un cliente por request/job: el contador de solicitudes refleja el consumo de esa operación.
        $this->app->scoped(MercadoPublicoV2Client::class);
        $this->app->scoped(MercadoPublicoV1Client::class);
        // Proveedor del modelo de los agentes IA (la clave se lee de config, nunca del navegador).
        $this->app->singleton(LlmClient::class, fn () => new AnthropicLlmClient(config('ai')));
    }

    public function boot(): void
    {
        // Enlace de invitación / restablecimiento hacia la SPA. Token y correo van en el fragmento (#):
        // el navegador no lo envía al servidor, así no queda en logs de acceso ni en cabeceras Referer.
        $resetUrl = fn ($user, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .'/restablecer#token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset());
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('TIARE: define tu contraseña')
            ->greeting('Hola, '.$user->name)
            ->line('Recibiste este correo para definir o recuperar tu contraseña de TIARE.')
            ->action('Definir contraseña', $resetUrl($user, $token))
            ->line('El enlace vence en '.config('auth.passwords.users.expire').' minutos. Si vence, usa "¿Olvidaste tu contraseña?" en la pantalla de acceso.')
            ->line('Si no esperabas este correo, puedes ignorarlo.')
            ->salutation('Equipo TIARE'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getKey() ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Cada detalle no cacheado consume cuota del ticket ChileCompra.
        RateLimiter::for('mp-detail', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->getKey() ?: $request->ip()));

        // Consultas V1 (licitaciones, OC, organismos, proveedores). Las cacheadas no consumen cuota, pero se limitan igual.
        RateLimiter::for('mp-v1', fn (Request $request) => Limit::perMinute(40)->by($request->user()?->getKey() ?: $request->ip()));

        // Agentes IA: cada ejecución con modelo tiene costo; además hay un límite diario por empresa.
        RateLimiter::for('agents', fn (Request $request) => Limit::perMinute(6)->by($request->user()?->getKey() ?: $request->ip()));
        // Cambio de contraseña propia: por cuenta, contra adivinación de la contraseña actual (TD-026).
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by('pwd|'.($request->user()?->getKey() ?: $request->ip())));
        // Generación de contraseñas de inicio: por administrador que la emite (TD-027).
        RateLimiter::for('initial-password', fn (Request $request) => Limit::perMinute(10)->by('initpwd|'.($request->user()?->getKey() ?: $request->ip())));
        // Subida de archivos (copias de documentos de Mercado Público).
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->getKey() ?: $request->ip()));

        RateLimiter::for('mp-sync', fn (Request $request) => Limit::perMinutes(10, 2)->by($request->user()?->getKey() ?: $request->ip()));
    }
}
