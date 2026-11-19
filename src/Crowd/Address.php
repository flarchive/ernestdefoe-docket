<?php

namespace ErnestDefoe\Docket\Crowd;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a member's flag came from, kept only as a keyed hash: reports from
 * one address count as one person.
 */
class Address
{
    /**
     * The address of the request that is filing a flag, set by the flag
     * endpoint just before the flag is saved and taken by the entry that
     * flag files (hash), so it is used once and never reaches
     * another flag, however long the process lives.
     */
    protected ?string $address = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Where a request came from. Flarum sees only the connection's address;
     * behind a reverse proxy on the same machine or network (Docker, a local
     * nginx) that is the proxy's for everyone. So when the connection is
     * from a private or loopback address, the proxy's X-Forwarded-For is
     * read from the right: the first public address in it is the one the
     * proxy saw. Entries to its left are whatever the client sent, and are
     * never trusted. A public connection's header is ignored.
     */
    public static function clientAddress(ServerRequestInterface $request): ?string
    {
        $remote = $request->getAttribute('ipAddress');

        if (! is_string($remote) || self::isPublic($remote)) {
            return is_string($remote) ? $remote : null;
        }

        $hops = array_reverse(array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))));

        foreach ($hops as $hop) {
            if (! filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }

            if (self::isPublic($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    protected static function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public function filingFrom(?string $address): void
    {
        $this->address = $address;
    }

    /**
     * A keyed hash of the current request's address, for a new entry. IPv6
     * by its /64, which one connection is usually given whole.
     */
    public function hash(): ?string
    {
        $ip = $this->address;
        $this->address = null;

        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 16) {
            $packed = str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
                ? substr($packed, 12)
                : substr($packed, 0, 8);
        }

        $salt = (string) $this->settings->get('ernestdefoe-docket.crowd_salt');

        // Without the key (its migration never ran), no hash: such a flag
        // never counts, rather than being hashed so it could be matched.
        return $salt === '' ? null : hash_hmac('sha256', $packed, $salt);
    }
}
