<?php

namespace Tests\Feature\MercadoPublico;

use App\Models\MercadoPublicoApiUsage;
use App\Services\MercadoPublico\Adapters\CompraAgilV2Adapter;
use App\Services\MercadoPublico\DTO\CompraAgilSearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MercadoPublicoV2ClientTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_TICKET = '00000000-TEST-TICKET-0000-000000000000';

    private function client(): MercadoPublicoV2Client
    {
        return app(MercadoPublicoV2Client::class);
    }

    private function error(int $status, string $detail = 'x'): array
    {
        return ['success' => 'NOK', 'trace' => null, 'payload' => null, 'errors' => [['codigo' => (string) $status, 'mensaje' => 'Error', 'detalle' => $detail]]];
    }

    public function test_ticket_is_sent_only_as_header_never_in_query(): void
    {
        Http::fake(['api2.mercadopublico.cl/*' => Http::response($this->fixture('compra_agil_list_page1'))]);

        app(CompraAgilV2Adapter::class)->search(new CompraAgilSearchQuery(statuses: ['publicada'], regions: [13]));

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('ticket', self::TEST_TICKET)
                && ! str_contains($request->url(), 'ticket')
                && ! str_contains($request->url(), self::TEST_TICKET)
                && str_starts_with($request->url(), 'https://api2.mercadopublico.cl/v2/compra-agil?')
                && $request['estado'] === 'publicada'
                && $request['region'] === '13'
                && (int) $request['tamano_pagina'] === 50;
        });
    }

    public function test_400_is_mapped_without_retry(): void
    {
        Http::fake(['*' => Http::response($this->error(400, 'tamano_pagina debe estar entre 10 y 50'), 400)]);

        try {
            $this->client()->get('/v2/compra-agil', ['tamano_pagina' => 50]);
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::INVALID_PARAMETERS, $e->errorCode);
            $this->assertStringContainsString('tamano_pagina debe estar entre 10 y 50', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_401_and_403_are_ticket_errors_without_retry(): void
    {
        foreach ([401, 403] as $status) {
            Http::fake(['*' => Http::response($this->error($status), $status)]);
            try {
                $this->client()->get('/v2/compra-agil');
                $this->fail('Se esperaba excepción');
            } catch (MercadoPublicoException $e) {
                $this->assertSame(MercadoPublicoException::UNAUTHORIZED, $e->errorCode);
                $this->assertStringNotContainsString(self::TEST_TICKET, $e->getMessage());
            }
        }
    }

    public function test_404_is_not_found(): void
    {
        Http::fake(['*' => Http::response($this->error(404), 404)]);
        $this->expectExceptionObject(new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, 'El registro no existe o no es público en Mercado Público.'));
        app(CompraAgilV2Adapter::class)->find('1-1-COT26');
    }

    public function test_429_respects_retry_after_and_blocks_without_retrying(): void
    {
        CarbonImmutable::setTestNow('2026-11-14 12:00:00');
        Http::fake(['*' => Http::response($this->error(429), 429, ['Retry-After' => '120'])]);

        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::QUOTA_EXHAUSTED, $e->errorCode);
            $this->assertSame('2026-11-14T12:02:00+00:00', $e->retryAfter?->toIso8601String());
        }
        Http::assertSentCount(1);

        // Mientras dure el bloqueo no se vuelve a llamar a la API.
        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::QUOTA_EXHAUSTED, $e->errorCode);
        }
        Http::assertSentCount(1);
        CarbonImmutable::setTestNow();
    }

    public function test_429_without_retry_after_blocks_until_next_calendar_day_in_santiago(): void
    {
        CarbonImmutable::setTestNow('2026-11-14 12:00:00'); // 09:00 en Santiago
        Http::fake(['*' => Http::response($this->error(429), 429)]);

        try {
            $this->client()->get('/v2/compra-agil');
        } catch (MercadoPublicoException) {
        }

        $usage = MercadoPublicoApiUsage::first();
        $this->assertSame('2026-11-15T03:00:00+00:00', $usage->blocked_until->toIso8601String()); // 00:00 del 15 en Santiago
        CarbonImmutable::setTestNow();
    }

    public function test_500_and_503_are_retried_with_backoff_then_succeed(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->error(500), 500)
            ->push($this->error(503), 503)
            ->push($this->fixture('compra_agil_list_page1'), 200)]);

        $payload = $this->client()->get('/v2/compra-agil');

        $this->assertArrayHasKey('items', $payload);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        $this->assertSame(3, MercadoPublicoApiUsage::first()->request_count);
    }

    public function test_retries_are_limited(): void
    {
        Http::fake(['*' => Http::response($this->error(503), 503)]);

        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::UPSTREAM_UNAVAILABLE, $e->errorCode);
        }
        Http::assertSentCount(3); // 1 + MERCADO_PUBLICO_MAX_RETRIES (2)
    }

    public function test_connection_errors_are_retried_and_reported_without_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 https://api2.mercadopublico.cl/v2/compra-agil?x=1'));

        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::UPSTREAM_UNAVAILABLE, $e->errorCode);
            $this->assertStringNotContainsString('http', $e->getMessage());
        }
    }

    public function test_unexpected_body_is_invalid_response(): void
    {
        Http::fake(['*' => Http::response(['success' => 'OK', 'payload' => null], 200)]);
        $this->expectExceptionObject(new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Mercado Público respondió con un formato inesperado.'));
        $this->client()->get('/v2/compra-agil');
    }

    public function test_local_daily_budget_stops_requests_before_calling_api(): void
    {
        config(['mercado_publico.v2.daily_budget' => 1]);
        Http::fake(['*' => Http::response($this->fixture('compra_agil_list_page1'))]);

        $this->client()->get('/v2/compra-agil');
        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::QUOTA_EXHAUSTED, $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_disabled_or_unconfigured_integration_never_calls_api(): void
    {
        Http::fake();
        config(['mercado_publico.ticket' => '']);
        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::NOT_CONFIGURED, $e->errorCode);
        }

        config(['mercado_publico.enabled' => false]);
        try {
            $this->client()->get('/v2/compra-agil');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::DISABLED, $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_failure_logs_never_contain_ticket(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response($this->error(400, 'detalle con '.self::TEST_TICKET), 400)]);

        try {
            $this->client()->get('/v2/compra-agil', ['q' => 'x']);
        } catch (MercadoPublicoException $e) {
            $this->assertStringNotContainsString(self::TEST_TICKET, $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) {
            return ! str_contains(json_encode([$message, $context]), self::TEST_TICKET)
                && $context['path'] === '/v2/compra-agil';
        });
    }

    public function test_invalid_code_is_rejected_before_any_request(): void
    {
        Http::fake();
        $this->expectException(MercadoPublicoException::class);
        try {
            app(CompraAgilV2Adapter::class)->find('../../admin');
        } finally {
            Http::assertNothingSent();
        }
    }
}
