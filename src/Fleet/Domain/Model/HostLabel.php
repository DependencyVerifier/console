<?php

declare(strict_types=1);

namespace Fleet\Domain\Model;

use function Symfony\Component\String\u;

/**
 * What a human says of a host: the name shown instead of its hostname (none: the hostname), and its IP addresses
 * (written by hand, never collected by the agent). The addresses are canonical (an IPv6 address once whatever its
 * spelling), each once, in the order given.
 */
final readonly class HostLabel
{
    public const int MAX_NAME = 100;
    public const int MAX_ADDRESSES = 16;

    public ?string $displayName;

    /** @var list<string> */
    public array $addresses;

    /**
     * @param list<string> $addresses
     */
    public function __construct(?string $displayName, array $addresses)
    {
        $name = u($displayName ?? '')->trim();
        if ($name->length() > self::MAX_NAME || [] !== $name->match('/\p{C}/u')) {
            throw new InvalidHostLabel('A display name of 100 printable characters at most.');
        }

        $this->displayName = $name->isEmpty() ? null : $name->toString();
        $canonical = [];
        foreach ($addresses as $address) {
            $address = u($address)->trim()->toString();
            $binary = false === filter_var($address, \FILTER_VALIDATE_IP) ? false : inet_pton($address);
            if (false === $binary) {
                throw new InvalidHostLabel('An IPv4 or IPv6 address.');
            }

            /** @var string $text a valid address always has a text form */
            $text = inet_ntop($binary);
            $canonical[] = $text;
        }

        $this->addresses = array_values(array_unique($canonical));
        if (\count($this->addresses) > self::MAX_ADDRESSES) {
            throw new InvalidHostLabel('16 IP addresses at most.');
        }
    }
}
