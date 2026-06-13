<?php

namespace pz;

class Version
{
    public string $version;

    public int $major;
    public int $minor;
    public int $patch;

    public function __construct(string $version)
    {
        $this->version = $this->normalizeVersion($version);
        $this->parseVersion();
    }

    public function normalizeVersion(string $version): string
    {
        // Remove any leading 'v' or 'V'
        $version = ltrim($version, 'vV');

        // Replace underscores and hyphens with dots
        $version = str_replace(['_', '-'], '.', $version);

        // Ensure the version has three parts (major, minor, patch)
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0'; // Add missing parts as '0'
        }

        return implode('.', $parts);
    }

    public function parseVersion()
    {
        $parts = explode('.', $this->version);
        $this->major = (int) $parts[0];
        $this->minor = (int) $parts[1];
        $this->patch = (int) $parts[2];
    }

    public function isNewerThan(Version|string $other): bool
    {
        if (is_string($other)) {
            $other = new Version($other);
        }

        if ($this->major > $other->major)
            return true;
        if ($this->major < $other->major)
            return false;

        if ($this->minor > $other->minor)
            return true;
        if ($this->minor < $other->minor)
            return false;

        return $this->patch > $other->patch;
    }

    public function getVersion(string $separator = '.'): string
    {
        return $this->major . $separator . $this->minor . $separator . $this->patch;
    }

}