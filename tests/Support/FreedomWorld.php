<?php

declare(strict_types=1);

namespace Freedom\Tests\Support;

use Fellowship\Auth\AudienceRegistry;
use Fellowship\Auth\DeviceCodeStore;
use Fellowship\Auth\DeviceRedirectValidator;
use Fellowship\Auth\DeviceTokenMinter;
use Fellowship\Auth\IdentityBroker;
use Fellowship\Auth\LinkAudience;
use Fellowship\Auth\ProviderRegistry;
use Fellowship\Auth\StateStore;
use Fellowship\Auth\VerifiedIdentity;
use Fellowship\Core\RateLimiter;
use Fellowship\Crypto\MessageSealer;
use Fellowship\Devices\CurrentDevice;
use Fellowship\Devices\MemberGate;
use Fellowship\Tests\Support\InMemoryDeviceRepository;
use Fellowship\Tests\Support\StubProvider;
use Freedom\Applications\Application;
use Freedom\Auth\FreedomAudience;
use Freedom\Auth\Pkce;
use Freedom\Auth\SignInContext;
use Freedom\Config\ConfigEditor;
use Freedom\Config\ValueSealer;
use Freedom\Core\Cipher;
use Freedom\Rest\ConfigController;
use Freedom\Rest\SignInController;
use Freedom\Rest\TabletController;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\DeviceIdHasher;
use Freedom\Tablets\Enrolment;
use Freedom\Tablets\TabletAudit;
use Freedom\Tablets\TabletGate;
use Freedom\Tablets\TabletTokenMinter;
use RuntimeException;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The whole of Freedom, built once per test over in-memory stores — with
 * Fellowship's real broker, member gate, code store and sealer behind it.
 *
 * <b>Fellowship's pieces are real on purpose.</b> The seam between the two
 * plugins is what is most likely to go wrong — an audience Fellowship
 * does not consult, a code it will not redeem, a session it will not
 * accept — and a fake broker would agree with whatever Freedom assumed.
 * Only Fellowship's device store is a double, and it is Fellowship's own.
 *
 * A class rather than file-level helpers because every Pest file in this
 * namespace sees every other file's functions: one object avoids a dozen
 * prefixed helper names.
 */
final class FreedomWorld
{
    public const MEMBER = 'member@example.org';
    public const TABLET_ACCOUNT = 'tablets@example.org';
    public const CALLBACK = 'org.example.register.freedom://auth';
    public const DEVICE = 'android-0001';

    public InMemoryMemberRepository $members;
    public MemberGate $memberGate;
    public InMemoryDeviceRepository $linkDevices;
    public DeviceTokenMinter $linkMinter;
    public StateStore $states;
    public DeviceCodeStore $codes;
    public StubProvider $google;
    public IdentityBroker $broker;
    public AudienceRegistry $audiences;

    public InMemoryApplicationRepository $applications;
    public InMemoryCommonAccountRepository $accounts;
    public InMemoryTabletRepository $tablets;
    public InMemoryValueRepository $values;
    public SpyAuditLogger $auditLogger;
    public TabletGate $gate;
    public TabletTokenMinter $minter;
    public DeviceIdHasher $hasher;
    public Cipher $cipher;
    public ConfigEditor $editor;
    public CurrentTablet $currentTablet;
    public Enrolment $enrolment;
    public TabletAudit $audit;

    public SignInController $signIn;
    public ConfigController $config;
    public TabletController $tablet;

    public Application $app;

    public function __construct()
    {
        $this->members = new InMemoryMemberRepository([
            new MemberStub(id: 7, anonymousName: 'Dave P', personalEmail: self::MEMBER),
        ]);
        $this->memberGate = new MemberGate($this->members);

        // Fellowship.
        $this->linkDevices = new InMemoryDeviceRepository();
        $this->linkMinter = new DeviceTokenMinter();
        $this->states = new StateStore();
        $this->codes = new DeviceCodeStore();
        $this->google = new StubProvider('google', serverSide: true);
        $providers = new ProviderRegistry();
        $providers->register($this->google);
        $this->audiences = new AudienceRegistry(new LinkAudience(new DeviceRedirectValidator(), $this->memberGate));
        $this->broker = new IdentityBroker(
            $this->audiences,
            $providers,
            $this->states,
            $this->codes,
            new CurrentDevice($this->linkDevices, $this->linkMinter, $this->memberGate),
            $this->linkDevices,
            $this->memberGate,
        );

        // Freedom.
        $this->applications = new InMemoryApplicationRepository();
        $this->accounts = new InMemoryCommonAccountRepository();
        $this->tablets = new InMemoryTabletRepository();
        $this->values = new InMemoryValueRepository();
        $this->auditLogger = new SpyAuditLogger();
        $this->audit = new TabletAudit($this->auditLogger);
        $this->gate = new TabletGate($this->memberGate, $this->accounts);
        $this->minter = new TabletTokenMinter();
        $this->hasher = new DeviceIdHasher();
        $this->cipher = new Cipher('freedom-values');
        $this->editor = new ConfigEditor($this->applications, $this->values, $this->cipher);
        $this->enrolment = new Enrolment($this->tablets, $this->minter, $this->audit);
        $this->currentTablet = new CurrentTablet($this->tablets, $this->applications, $this->minter, $this->gate, $this->broker);

        $this->broker->registerAudience(new FreedomAudience($this->applications, $this->gate));

        $limiter = new RateLimiter();
        $this->signIn = new SignInController($this->applications, $this->broker, $this->gate, $this->enrolment, $this->hasher, $this->minter, $limiter);
        $this->config = new ConfigController($this->currentTablet, $this->values, $this->editor, new ValueSealer(new MessageSealer()), $limiter);
        $this->tablet = new TabletController($this->currentTablet, $this->tablets, $this->audit);

        $this->app = $this->applications->add('register', self::CALLBACK, allowLoopback: true, acceptFellowshipSessions: false);
        $this->accounts->add($this->app->id, self::TABLET_ACCOUNT, 'Register tablets', 1, 1_700_000_000);
    }

    /** Re-read the application after a revision or a switch changed it. */
    public function app(): Application
    {
        return $this->applications->findById($this->app->id) ?? throw new RuntimeException('application gone');
    }

    // ── Requests ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function request(array $params = [], string $token = '', array $headers = []): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        if ($token !== '') {
            $request->set_header('authorization', 'Bearer ' . $token);
        }

        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return $request;
    }

    // ── Keys ──────────────────────────────────────────────────────────

    /**
     * A tablet keypair, generated once per run: [private PEM, base64 SPKI].
     *
     * @return array{0: string, 1: string}
     */
    public static function keypair(): array
    {
        static $pair = null;

        if ($pair === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($resource === false) {
                test()->markTestSkipped('OpenSSL could not generate a keypair. Set OPENSSL_CONF.');
            }

            openssl_pkey_export($resource, $private);
            $details = openssl_pkey_get_details($resource);
            $public = preg_replace('/\s+|-----[^-]*-----/', '', (string) $details['key']) ?? '';
            $pair = [(string) $private, $public];
        }

        return $pair;
    }

    /**
     * Open a sealed value the way the C# library does: unwrap the content
     * key with RSA-OAEP-SHA1, split nonce(12) | tag(16) | ciphertext,
     * AES-256-GCM, gunzip, JSON. Nothing here calls Freedom's code.
     *
     * @return array<string, mixed>
     */
    public static function open(string $k, string $p, string $privatePem): array
    {
        $contentKey = '';
        if (!openssl_private_decrypt((string) base64_decode($k, true), $contentKey, $privatePem, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('The content key did not unwrap.');
        }

        $raw = (string) base64_decode($p, true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $contentKey, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('The payload did not authenticate.');
        }

        return (array) json_decode((string) gzdecode($plain), true);
    }

    // ── Journeys ──────────────────────────────────────────────────────

    /**
     * A browser sign-in, start to exchange, as the app would make it.
     *
     * @param array<string, mixed> $overrides Exchange parameters to replace.
     */
    public function signInThroughBrowser(string $email = self::MEMBER, string $deviceId = self::DEVICE, array $overrides = []): WP_REST_Response|\WP_Error
    {
        $verifier = str_repeat('v', 64);
        $code = $this->codes->issue(
            new VerifiedIdentity($email, 'google', 'sub-' . $email),
            FreedomAudience::NAME,
            (new SignInContext($this->app->slug, Pkce::challengeFor($verifier)))->encode(),
        );

        return $this->signIn->exchange($this->request($overrides + [
            'application'   => $this->app->slug,
            'code'          => $code,
            'code_verifier' => $verifier,
            'device_id'     => $deviceId,
            'public_key'    => self::keypair()[1],
            'label'         => 'Register tablet',
            'platform'      => 'android',
            'model'         => 'SM-X200',
            'app_version'   => '1.3.0',
        ]));
    }

    /** Enrol a tablet and answer its token. */
    public function enrolledToken(string $email = self::MEMBER, string $deviceId = self::DEVICE): string
    {
        $response = $this->signInThroughBrowser($email, $deviceId);
        if (!$response instanceof WP_REST_Response) {
            throw new RuntimeException('Enrolment failed: ' . $response->get_error_code());
        }

        return (string) ((array) $response->get_data())['token'];
    }

    /** Enrol a Link handset in Fellowship and answer its device token. */
    public function linkToken(string $email = self::MEMBER): string
    {
        $token = $this->linkMinter->mint();
        $this->linkDevices->create($this->linkMinter->hash($token), $email, 7, 'Pixel', 'android', self::keypair()[1], '', '', time());

        return $token;
    }
}
