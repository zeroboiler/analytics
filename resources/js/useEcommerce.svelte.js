/**
 * ZeroBoiler Analytics — Svelte Composable: useEcommerce
 *
 * Reactive e-commerce tracking composable for Svelte/Inertia applications.
 * Provides a reactive cart store, purchase tracking, item-level events,
 * and automatic currency/brand injection from Inertia props.
 *
 * Integrates with the core analytics.js client library for event dispatch.
 *
 * @package ZeroBoiler Analytics
 * @version 252.0.0
 */

import { writable, derived, get } from 'svelte/store';
import { page } from '@inertiajs/svelte';
import {
    trackEvent,
    trackEcommerce,
    trackEcommerceWithProviders,
    flushQueue,
    getTrackingId,
    getUserId,
    isInitialized,
} from './analytics.js';

// ─── Reactive Stores ──────────────────────────────────────────────

/**
 * Current cart state — list of items added but not yet purchased.
 * @type {import('svelte/store').Writable<Array<{item_id: string, item_name?: string, item_category?: string, price: number, quantity: number, item_variant?: string, item_brand?: string}>>}
 */
export const cartItems = writable([]);

/**
 * Cart totals derived from cartItems.
 * @type {import('svelte/store').Readable<{itemCount: number, totalValue: number, uniqueItems: number}>}
 */
export const cartTotals = derived(cartItems, ($items) => {
    let totalValue = 0;
    let itemCount = 0;
    for (const item of $items) {
        totalValue += (item.price || 0) * (item.quantity || 1);
        itemCount += item.quantity || 1;
    }
    return {
        itemCount,
        totalValue: Math.round(totalValue * 100) / 100,
        uniqueItems: $items.length,
    };
});

/**
 * Recently tracked purchase for confirmation UI.
 * @type {import('svelte/store').Writable<{transactionId: string, value: number, currency: string, itemCount: number, timestamp: number}|null>}
 */
export const lastPurchase = writable(null);

/**
 * Recently tracked refund for UI feedback.
 * @type {import('svelte/store').Writable<{transactionId: string, value: number, currency: string, timestamp: number}|null>}
 */
export const lastRefund = writable(null);

/**
 * Whether the ecommerce composable has been initialized.
 * @type {import('svelte/store').Writable<boolean>}
 */
export const initialized = writable(false);

/**
 * Configuration from Inertia props (zbAnalytics.ecommerce).
 * @type {import('svelte/store').Writable<{currency: string, brand: string, taxBehavior: string, shippingDefault: number}>}
 */
export const config = writable({
    currency: 'USD',
    brand: '',
    taxBehavior: 'inclusive',
    shippingDefault: 0,
});

// ─── Cart Management ───────────────────────────────────────────────

/**
 * Add an item to the reactive cart and fire an add_to_cart event.
 *
 * @param {{item_id: string, item_name?: string, item_category?: string, price: number, quantity?: number, item_variant?: string, item_brand?: string}} item
 * @param {object} [options]
 * @param {string} [options.itemListName] - Item list name for GA4
 * @param {string} [options.itemListId] - Item list ID for GA4
 */
export async function addToCart(item, options = {}) {
    if (!isInitialized()) {
        console.warn('[ZB Analytics] useEcommerce: analytics not initialized, skipping addToCart');
        return;
    }

    const quantity = item.quantity || 1;
    const normalizedItem = {
        item_id: String(item.item_id),
        item_name: item.item_name || '',
        item_category: item.item_category || '',
        price: Number(item.price) || 0,
        quantity: Number(quantity),
        item_variant: item.item_variant || '',
        item_brand: item.item_brand || '',
    };

    // Update cart store (merge quantities if item already exists)
    cartItems.update(($items) => {
        const existingIndex = $items.findIndex((i) => i.item_id === normalizedItem.item_id);
        if (existingIndex >= 0) {
            $items[existingIndex].quantity += normalizedItem.quantity;
            return $items;
        }
        return [...$items, normalizedItem];
    });

    // Build params with currency from config
    const cfg = get(config);
    const params = {
        currency: cfg.currency,
        value: normalizedItem.price * normalizedItem.quantity,
        items: [{ ...normalizedItem, ...(cfg.brand ? { item_brand: cfg.brand } : {}) }],
    };
    if (options.itemListName) {
        params.item_list_name = options.itemListName;
    }
    if (options.itemListId) {
        params.item_list_id = options.itemListId;
    }

    await trackEcommerce('add_to_cart', params);
}

/**
 * Remove an item from the reactive cart and fire a remove_from_cart event.
 *
 * @param {string} itemId - The item_id to remove
 * @param {number} [quantity=1] - Quantity to remove
 */
export async function removeFromCart(itemId, quantity = 1) {
    if (!isInitialized()) return;

    const cfg = get(config);
    let removedItem = null;

    cartItems.update(($items) => {
        const index = $items.findIndex((i) => i.item_id === itemId);
        if (index < 0) return $items;

        removedItem = $items[index];
        const newQty = removedItem.quantity - quantity;

        if (newQty <= 0) {
            return $items.filter((i) => i.item_id !== itemId);
        }

        const updated = [...$items];
        updated[index] = { ...updated[index], quantity: newQty };
        return updated;
    });

    if (removedItem) {
        await trackEcommerce('remove_from_cart', {
            currency: cfg.currency,
            value: removedItem.price * quantity,
            items: [{
                item_id: removedItem.item_id,
                item_name: removedItem.item_name,
                item_category: removedItem.item_category,
                price: removedItem.price,
                quantity,
            }],
        });
    }
}

/**
 * Clear the entire cart store.
 * Does NOT fire an analytics event — use before or after a purchase/refund.
 */
export function clearCart() {
    cartItems.set([]);
}

/**
 * Get the current cart snapshot for checkout events.
 *
 * @returns {{items: Array, itemCount: number, totalValue: number}}
 */
export function getCartSnapshot() {
    const totals = get(cartTotals);
    return {
        items: get(cartItems),
        itemCount: totals.itemCount,
        totalValue: totals.totalValue,
    };
}

// ─── E-commerce Event Tracking ────────────────────────────────────

/**
 * Track a view_item event.
 *
 * @param {{item_id: string, item_name?: string, item_category?: string, price: number, quantity?: number, item_variant?: string}} item
 */
export async function viewItem(item) {
    if (!isInitialized()) return;

    const cfg = get(config);
    const normalizedItem = {
        item_id: String(item.item_id),
        item_name: item.item_name || '',
        item_category: item.item_category || '',
        price: Number(item.price) || 0,
        quantity: Number(item.quantity) || 1,
        item_variant: item.item_variant || '',
    };

    await trackEcommerce('view_item', {
        currency: cfg.currency,
        value: normalizedItem.price * normalizedItem.quantity,
        items: [{ ...normalizedItem, ...(cfg.brand ? { item_brand: cfg.brand } : {}) }],
    });
}

/**
 * Track a view_cart event with all current cart items.
 */
export async function viewCart() {
    if (!isInitialized()) return;

    const items = get(cartItems);
    const totals = get(cartTotals);
    const cfg = get(config);

    if (items.length === 0) return;

    await trackEcommerce('view_cart', {
        currency: cfg.currency,
        value: totals.totalValue,
        items: items.map((i) => ({
            item_id: i.item_id,
            item_name: i.item_name,
            item_category: i.item_category,
            price: i.price,
            quantity: i.quantity,
        })),
    });
}

/**
 * Track a begin_checkout event.
 *
 * @param {object} [options]
 * @param {number} [options.coupon] - Coupon code applied
 * @param {string} [options.shippingTier] - Shipping tier selected
 */
export async function beginCheckout(options = {}) {
    if (!isInitialized()) return;

    const items = get(cartItems);
    const totals = get(cartTotals);
    const cfg = get(config);

    if (items.length === 0) return;

    const params = {
        currency: cfg.currency,
        value: totals.totalValue,
        items: items.map((i) => ({
            item_id: i.item_id,
            item_name: i.item_name,
            item_category: i.item_category,
            price: i.price,
            quantity: i.quantity,
        })),
    };

    if (options.coupon) {
        params.coupon = options.coupon;
    }
    if (options.shippingTier) {
        params.shipping_tier = options.shippingTier;
    }

    await trackEcommerce('begin_checkout', params);
}

/**
 * Track a purchase event and clear the cart.
 *
 * @param {{transaction_id: string, value?: number, currency?: string, tax?: number, shipping?: number, coupon?: string, affiliation?: string, payment_type?: string}} order
 * @param {Array} [orderItems] - Items to include (defaults to cart items)
 */
export async function purchase(order, orderItems) {
    if (!isInitialized()) return;

    const cfg = get(config);
    const items = orderItems || get(cartItems);
    const totals = get(cartTotals);

    const params = {
        transaction_id: String(order.transaction_id),
        value: Number(order.value ?? totals.totalValue),
        currency: String(order.currency || cfg.currency),
        items: items.map((i) => ({
            item_id: i.item_id,
            item_name: i.item_name,
            item_category: i.item_category,
            price: i.price,
            quantity: i.quantity,
        })),
    };

    if (order.tax) {
        params.tax = Number(order.tax);
    }
    if (order.shipping !== undefined) {
        params.shipping = Number(order.shipping);
    } else {
        params.shipping = cfg.shippingDefault;
    }
    if (order.coupon) {
        params.coupon = String(order.coupon);
    }
    if (order.affiliation) {
        params.affiliation = String(order.affiliation);
    }
    if (order.payment_type) {
        params.payment_type = String(order.payment_type);
    }

    await trackEcommerce('purchase', params);

    // Update last purchase store
    lastPurchase.set({
        transactionId: params.transaction_id,
        value: params.value,
        currency: params.currency,
        itemCount: items.reduce((sum, i) => sum + (i.quantity || 1), 0),
        timestamp: Date.now(),
    });

    // Clear the cart
    clearCart();

    // Flush queue to ensure purchase is dispatched immediately
    await flushQueue();
}

/**
 * Track a refund event.
 *
 * @param {{transaction_id: string, value?: number, currency?: string}} refund
 * @param {Array} [refundedItems] - Refunded line items
 */
export async function refund(refund, refundedItems = []) {
    if (!isInitialized()) return;

    const cfg = get(config);

    const params = {
        transaction_id: String(refund.transaction_id),
        value: Number(refund.value || 0),
        currency: String(refund.currency || cfg.currency),
    };

    if (refundedItems.length > 0) {
        params.items = refundedItems.map((i) => ({
            item_id: i.item_id,
            item_name: i.item_name,
            item_category: i.item_category,
            price: i.price,
            quantity: i.quantity,
        }));
    }

    await trackEcommerce('refund', params);

    lastRefund.set({
        transactionId: params.transaction_id,
        value: params.value,
        currency: params.currency,
        timestamp: Date.now(),
    });
}

/**
 * Track a select_item event.
 *
 * @param {Array<{item_id: string, item_name?: string, item_category?: string, price: number}>} items
 * @param {string} [itemListId]
 * @param {string} [itemListName]
 */
export async function selectItem(items, itemListId, itemListName) {
    if (!isInitialized()) return;

    const cfg = get(config);
    const params = {
        currency: cfg.currency,
        value: items.reduce((sum, i) => sum + ((i.price || 0) * (i.quantity || 1)), 0),
        items: items.map((i) => ({
            item_id: String(i.item_id),
            item_name: i.item_name || '',
            item_category: i.item_category || '',
            price: Number(i.price) || 0,
            quantity: Number(i.quantity) || 1,
        })),
    };

    if (itemListId) {
        params.item_list_id = itemListId;
    }
    if (itemListName) {
        params.item_list_name = itemListName;
    }

    await trackEcommerce('select_item', params);
}

/**
 * Track a generic e-commerce event with provider targeting.
 *
 * @param {string} eventName
 * @param {object} data
 * @param {string[]} [providers=[]]
 */
export async function trackWithProviders(eventName, data = {}, providers = []) {
    if (!isInitialized()) return;

    const cfg = get(config);
    data.currency = data.currency || cfg.currency;

    await trackEcommerceWithProviders(eventName, data, providers);
}

// ─── Initialization ──────────────────────────────────────────────

/**
 * Initialize the ecommerce composable from Inertia page props.
 *
 * Reads ecommerce configuration (currency, brand, tax behavior) from
 * zbAnalytics.ecommerce and sets up the reactive stores.
 *
 * @param {object} [pageProps] - Inertia page props (defaults to current page)
 * @returns {{cartItems: *, cartTotals: *, lastPurchase: *, lastRefund: *, config: *, initialized: *, addToCart, removeFromCart, clearCart, getCartSnapshot, viewItem, viewCart, beginCheckout, purchase, refund, selectItem, trackWithProviders}}
 */
export function useEcommerce(pageProps) {
    const props = pageProps || (typeof page !== 'undefined' ? get(page) : null);
    const analyticsProps = props?.props?.zbAnalytics || props?.zbAnalytics || {};

    // Load config from Inertia props
    if (analyticsProps.ecommerce) {
        config.set({
            currency: analyticsProps.ecommerce.currency || 'USD',
            brand: analyticsProps.ecommerce.brand || '',
            taxBehavior: analyticsProps.ecommerce.taxBehavior || 'inclusive',
            shippingDefault: analyticsProps.ecommerce.shippingDefault || 0,
        });
    }

    initialized.set(true);

    return {
        // Stores
        cartItems,
        cartTotals,
        lastPurchase,
        lastRefund,
        config,
        initialized,

        // Cart management
        addToCart,
        removeFromCart,
        clearCart,
        getCartSnapshot,

        // Event tracking
        viewItem,
        viewCart,
        beginCheckout,
        purchase,
        refund,
        selectItem,
        trackWithProviders,
    };
}

export default useEcommerce;
