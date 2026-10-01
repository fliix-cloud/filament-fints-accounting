<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Models\BankInstitute;
use FilamentAccounting\Banking\FinTs\Services\InstituteDirectoryService;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\RedactingLogger;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

class SecurityBoundaryTest extends TestCase
{
    #[Test]
    public function endpoint_validation_enforces_https_credentials_private_networks_and_allowlists(): void
    {
        config()->set('filament-accounting.banking.fints.security.https_only', true);
        config()->set('filament-accounting.banking.fints.security.allow_private_endpoints', false);
        config()->set('filament-accounting.banking.fints.security.allowed_hosts', []);

        $this->assertSame(
            'https://fints.example-bank.de/cgi/fints',
            EndpointValidator::validate('https://fints.example-bank.de/cgi/fints'),
        );

        foreach ([
            'http://fints.example-bank.de/cgi/fints',
            'https://user:pass@fints.example-bank.de/cgi/fints',
            'https://127.0.0.1/cgi/fints',
        ] as $invalid) {
            try {
                EndpointValidator::validate($invalid);
                $this->fail("The unsafe endpoint {$invalid} must be rejected.");
            } catch (FintsValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        config()->set('filament-accounting.banking.fints.security.allowed_hosts', ['fints.example-bank.de']);
        $this->expectException(FintsValidationException::class);
        EndpointValidator::validate('https://fints.other-bank.de/cgi/fints');
    }

    #[Test]
    public function institute_directory_downloads_reject_private_and_non_https_urls(): void
    {
        $directory = app(InstituteDirectoryService::class);

        foreach ([
            'http://example.com/blz.properties',
            'https://127.0.0.1/blz.properties',
            'https://169.254.169.254/latest/meta-data/',
        ] as $url) {
            try {
                $directory->sync($url);
                $this->fail("The unsafe directory URL {$url} must be rejected.");
            } catch (FintsValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function institute_directory_rejects_unsafe_endpoints_and_reports_changed_ones(): void
    {
        config()->set('filament-accounting.banking.fints.security.https_only', true);
        // The faked host may resolve locally (e.g. Herd/Valet *.test); the download guard is covered above.
        config()->set('filament-accounting.banking.fints.security.allow_private_endpoints', true);
        $url = 'https://directory.example.test/blz.properties';
        BankInstitute::query()->create([
            'bank_code' => '10000001',
            'name' => 'Alte Bank',
            'pin_tan_url' => 'https://fints.alte-bank.example/fints',
            'has_pin_tan' => true,
            'source' => 'hbci4j/hbci4java',
        ]);
        BankInstitute::query()->create([
            'bank_code' => '10000002',
            'name' => 'Sichere Bank',
            'pin_tan_url' => 'https://fints.sichere-bank.example/fints',
            'has_pin_tan' => true,
            'source' => 'hbci4j/hbci4java',
        ]);
        Http::fake([$url => Http::response(implode("\n", [
            '10000001=Alte Bank|Berlin|ALTEDEBBXXX|00||https://evil.example/fints|300|300',
            '10000002=Sichere Bank|Berlin|SICHDEBBXXX|00||http://fints.sichere-bank.example/fints|300|300',
            '10000003=Neue Bank|Berlin|NEUEDEBBXXX|00||https://user:pw@fints.neue-bank.example/fints|300|300',
            '10000004=Gute Bank|Berlin|GUTEDEBBXXX|00||fints.gute-bank.example/fints|300|300',
        ]))]);

        $result = app(InstituteDirectoryService::class)->sync($url);

        $this->assertSame([['bank_code' => '10000001', 'from' => 'https://fints.alte-bank.example/fints', 'to' => 'https://evil.example/fints']], $result['changed']);
        $this->assertSame(['10000002', '10000003'], $result['rejected']);
        // A rejected row must not downgrade the existing endpoint.
        $this->assertSame('https://fints.sichere-bank.example/fints', BankInstitute::query()->where('bank_code', '10000002')->value('pin_tan_url'));
        $this->assertFalse(BankInstitute::query()->where('bank_code', '10000003')->exists());
        $this->assertSame('https://fints.gute-bank.example/fints', BankInstitute::query()->where('bank_code', '10000004')->value('pin_tan_url'));

        $this->artisan('filament-accounting:sync-institutes', ['--url' => $url])
            ->expectsOutputToContain('2 invalid endpoint(s) ignored: 10000002, 10000003')
            ->assertSuccessful();
    }

    #[Test]
    public function protocol_logging_redacts_credentials_state_and_frames(): void
    {
        config()->set('filament-accounting.banking.fints.security.protocol_debug', false);
        $calls = [];
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function ($level, $message, array $context) use (&$calls): void {
                $calls[] = [$level, (string) $message, $context];
            });
        $logger = new RedactingLogger($inner);

        $logger->debug('PIN=123456 TAN=998877 HNVSD:private-payload', [
            'pin' => '123456',
            'tan' => '998877',
            'persisted' => 'dialog-secret',
        ]);
        $logger->debug('HNHBK:1:3+frame-secret');

        $serialized = serialize($calls);
        foreach (['123456', '998877', 'private-payload', 'dialog-secret', 'frame-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertStringContainsString('[redacted]', $serialized);
        $this->assertStringContainsString('[fints-frame-redacted]', $serialized);
    }
}
