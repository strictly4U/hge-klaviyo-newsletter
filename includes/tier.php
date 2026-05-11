<?php
/**
 * Tier helper — Free plugin's view of the Pro extension.
 *
 * The Pro plugin defines `HGE_KLAVIYO_PRO_VERSION` and may expose
 * `hge_klaviyo_pro_active_plan()` returning 'core' | 'pro' | 'inactive'.
 * Free uses these to render upgrade CTAs and to skip Free defaults
 * when Pro overrides them via filters.
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'hge_klaviyo_is_pro_active' ) ) {
    /**
     * True when the Pro extension plugin is loaded (regardless of license state).
     */
    function hge_klaviyo_is_pro_active() {
        return defined( 'HGE_KLAVIYO_PRO_VERSION' );
    }
}

if ( ! function_exists( 'hge_klaviyo_active_plan' ) ) {
    /**
     * Returns 'free' | 'core' | 'pro'.
     * Free → Pro not loaded.
     * Core → Pro loaded, license valid for Core plan (Tier 2 features).
     * Pro  → Pro loaded, license valid for Pro plan (Tier 3 features).
     */
    function hge_klaviyo_active_plan() {
        if ( ! hge_klaviyo_is_pro_active() ) {
            return 'free';
        }
        if ( function_exists( 'hge_klaviyo_pro_active_plan' ) ) {
            $plan = hge_klaviyo_pro_active_plan();
            if ( in_array( $plan, array( 'core', 'pro' ), true ) ) {
                return $plan;
            }
        }
        return 'free';
    }
}

if ( ! function_exists( 'hge_klaviyo_upgrade_cta_html' ) ) {
    /**
     * Inline HTML badge nudging the user to upgrade (used in the Settings tab
     * next to features that require Core or Pro).
     *
     * @param string $required Plan key required to unlock: 'core' or 'pro'.
     */
    function hge_klaviyo_upgrade_cta_html( $required = 'core' ) {
        $label = ( 'pro' === $required ) ? 'Available in Pro plan' : 'Available in Core plan';
        $color = ( 'pro' === $required ) ? '#7b1fa2' : '#1565c0';
        return '<span style="display:inline-block;margin-left:8px;padding:2px 8px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;border-radius:3px;">' . esc_html( $label ) . '</span>';
    }
}
