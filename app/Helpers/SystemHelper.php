<?php

if (!function_exists('setting')) {
    /**
     * Get a system setting value
     */
    function setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('app_setting')) {
    /**
     * Get application setting
     */
    function app_setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('microfinance_setting')) {
    /**
     * Get microfinance specific setting
     */
    function microfinance_setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('is_maintenance_mode')) {
    /**
     * Check if maintenance mode is enabled
     */
    function is_maintenance_mode()
    {
        return \App\Services\SystemSettingService::isMaintenanceMode();
    }
}

if (!function_exists('get_maintenance_message')) {
    /**
     * Get maintenance message
     */
    function get_maintenance_message()
    {
        return \App\Services\SystemSettingService::getMaintenanceMessage();
    }
}

if (!function_exists('format_currency')) {
    /**
     * Format currency based on system settings
     */
    function format_currency($amount, $currency = null)
    {
        $currency = $currency ?: setting('currency', 'TZS');
        $symbol = setting('currency_symbol', 'TSh');
        
        return $symbol . number_format($amount, 2);
    }
}

if (!function_exists('format_date')) {
    /**
     * Format date based on system settings
     */
    function format_date($date, $format = null)
    {
        $format = $format ?: setting('date_format', 'Y-m-d');
        
        if ($date instanceof \Carbon\Carbon) {
            return $date->format($format);
        }
        
        return \Carbon\Carbon::parse($date)->format($format);
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Format datetime based on system settings
     */
    function format_datetime($datetime, $dateFormat = null, $timeFormat = null)
    {
        $dateFormat = $dateFormat ?: setting('date_format', 'Y-m-d');
        $timeFormat = $timeFormat ?: setting('time_format', 'H:i:s');
        $format = $dateFormat . ' ' . $timeFormat;
        
        if ($datetime instanceof \Carbon\Carbon) {
            return $datetime->format($format);
        }
        
        return \Carbon\Carbon::parse($datetime)->format($format);
    }
}

if (!function_exists('update_env_file')) {
    /**
     * Update or add environment variable in .env file
     */
    function update_env_file($key, $value)
    {
        $envFile = base_path('.env');
        
        if (!file_exists($envFile)) {
            return false;
        }
        
        // Read the .env file
        $envContent = file_get_contents($envFile);
        
        // Handle value escaping - wrap in quotes if it contains spaces or special characters
        $needsQuotes = preg_match('/[\s#\$"\'\\\]/', $value);
        if ($needsQuotes) {
            // Escape quotes and backslashes in the value
            $escapedValue = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        } else {
            $escapedValue = $value;
        }
        
        // Check if key exists (handle both with and without quotes)
        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
        
        if (preg_match($pattern, $envContent)) {
            // Update existing key
            $envContent = preg_replace($pattern, $key . '=' . $escapedValue, $envContent);
        } else {
            // Add new key at the end (before any comments at the end)
            // Find the last non-empty, non-comment line
            $lines = explode("\n", $envContent);
            $lastNonEmptyIndex = -1;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $lastNonEmptyIndex = $i;
                    break;
                }
            }
            
            if ($lastNonEmptyIndex >= 0) {
                // Insert after the last non-empty line
                array_splice($lines, $lastNonEmptyIndex + 1, 0, $key . '=' . $escapedValue);
                $envContent = implode("\n", $lines);
            } else {
                // Just append
                $envContent .= "\n" . $key . '=' . $escapedValue;
            }
        }
        
        // Write back to file
        return file_put_contents($envFile, $envContent) !== false;
    }
} 