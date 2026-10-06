<?php

namespace App\Crawling;

final class RobotsRules
{
    public function allows(string $robotsTxt, string $path, string $agent = 'YaanduGrowthBot'): bool
    {
        $active = false;
        $rules = [];
        foreach (preg_split('/\r?\n/', $robotsTxt) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (! str_contains($line, ':')) continue;
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if (strtolower($key) === 'user-agent') $active = $value === '*' || stripos($agent, $value) !== false;
            elseif ($active && strtolower($key) === 'disallow' && $value !== '') $rules[] = $value;
        }
        foreach ($rules as $rule) if (str_starts_with($path, $rule)) return false;
        return true;
    }
}
