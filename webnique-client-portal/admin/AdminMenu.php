<?php
/**
 * Admin Menu Handler
 * 
 * @package WebNique Portal
 */

namespace WNQ\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AdminMenu
 * Handles WordPress admin menu registration
 */
final class AdminMenu
{
    /**
     * Register admin menu hooks
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
    }

    /**
     * Add admin menu and submenu pages
     */
    public static function addMenu(): void
    {
        // Check if user has custom capability, fallback to manage_options
        $capability = current_user_can('wnq_manage_portal') 
            ? 'wnq_manage_portal' 
            : 'manage_options';

        // Add main menu page
        add_menu_page(
            'WebNique Portal',                    // Page title
            'WebNique Portal',                    // Menu title
            $capability,                          // Capability
            'wnq-portal',                        // Menu slug
            [AdminSettings::class, 'render'],    // Callback
            'dashicons-chart-area',              // Icon
            58                                    // Position
        );

        // Add settings submenu page
        add_submenu_page(
            'wnq-portal',                        // Parent slug
            'Settings',                           // Page title
            'Settings',                           // Menu title
            $capability,                          // Capability
            'wnq-portal',                        // Menu slug (same as parent for first submenu)
            [AdminSettings::class, 'render']     // Callback
        );
    }
}