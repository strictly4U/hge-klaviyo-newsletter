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

if ( ! function_exists( 'hge_klaviyo_nl_tier_min_interval_hours' ) ) {
    /**
     * Hard tier-based floor for the per-rule cooldown between newsletter
     * dispatches. The customer-facing `min_interval_hours` setting in
     * Settings is fully editable on every tier, but the dispatcher always
     * applies MAX(customer_setting, tier_floor) so Free + Core customers
     * can't bypass the tier upsell by saving an artificially low value.
     *
     * Pro = 0 (no floor; customer setting is the only gate, can be 0h).
     * Core = 144h (6 days).
     * Free = 720h (30 days).
     *
     * Re-evaluated at dispatch time, not at save time, so a license
     * downgrade (Pro → Free at expiration) takes effect on the very next
     * publish without needing the customer to re-save anything.
     *
     * @since 3.0.14 (FcRapid1923-omh)
     *
     * @param string|null $plan Plan key. When null, falls back to the
     *                          currently active plan via hge_klaviyo_active_plan().
     * @return int Tier-imposed minimum interval in hours (0 = no floor).
     */
    function hge_klaviyo_nl_tier_min_interval_hours( $plan = null ) {
        if ( null === $plan ) {
            $plan = hge_klaviyo_active_plan();
        }
        switch ( (string) $plan ) {
            case 'pro':
                return 0;            // no floor — customer setting governs
            case 'core':
                return 6 * 24;       // 144 hours = 6 days
            case 'free':
            default:
                return 30 * 24;      // 720 hours = 30 days
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_effective_min_interval_hours' ) ) {
    /**
     * Effective minimum interval the dispatcher actually applies, given
     * the customer's setting and the tier floor.
     *
     * @since 3.0.14 (FcRapid1923-omh)
     *
     * @param int         $customer_hours Value from settings (min_interval_hours).
     * @param string|null $plan           Optional plan override; default = current.
     * @return int        Max of the two.
     */
    function hge_klaviyo_nl_effective_min_interval_hours( $customer_hours, $plan = null ) {
        $customer_hours = max( 0, (int) $customer_hours );
        $tier_floor     = (int) hge_klaviyo_nl_tier_min_interval_hours( $plan );
        return max( $customer_hours, $tier_floor );
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
        $label = ( 'pro' === $required )
            ? __( 'Available in Pro plan', 'hge-automated-post-campaigns-for-klaviyo' )
            : __( 'Available in Core plan', 'hge-automated-post-campaigns-for-klaviyo' );
        $color = ( 'pro' === $required ) ? '#7b1fa2' : '#1565c0';
        return '<span style="display:inline-block;margin-left:8px;padding:2px 8px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;border-radius:3px;">' . esc_html( $label ) . '</span>';
    }
}
