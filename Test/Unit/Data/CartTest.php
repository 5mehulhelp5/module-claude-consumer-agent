<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use PHPUnit\Framework\TestCase;

class CartTest extends TestCase
{
    public function testFindReturnsMatchingItem(): void
    {
        $itemOne = new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1);
        $itemTwo = new CartItem(productId: 'SKU-2', title: 'Two', price: 20.0, quantity: 2);
        $cart = new Cart(items: [$itemOne, $itemTwo]);

        $this->assertSame($itemTwo, $cart->find('SKU-2'));
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $cart = new Cart();

        $this->assertNull($cart->find('SKU-404'));
    }

    public function testIsEmpty(): void
    {
        $emptyCart = new Cart();
        $filledCart = new Cart(items: [new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1)]);

        $this->assertTrue($emptyCart->isEmpty());
        $this->assertFalse($filledCart->isEmpty());
    }

    public function testItemCountSumsQuantities(): void
    {
        $cart = new Cart(items: [
            new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 2),
            new CartItem(productId: 'SKU-2', title: 'Two', price: 20.0, quantity: 3),
        ]);

        $this->assertSame(5, $cart->getItemCount());
    }

    public function testSubtotalSumsLineTotals(): void
    {
        $cart = new Cart(items: [
            new CartItem(productId: 'SKU-1', title: 'One', price: 9.995, quantity: 2),
            new CartItem(productId: 'SKU-2', title: 'Two', price: 5.0, quantity: 1),
        ]);

        $this->assertSame(24.99, $cart->getSubtotal());
    }

    public function testFromArrayBuildsItemsAndComputesTotals(): void
    {
        $cart = Cart::fromArray([
            'currency' => 'CAD',
            'items' => [
                ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('CAD', $cart->getCurrency());
        $this->assertSame(2, $cart->getItemCount());
        $this->assertSame(20.0, $cart->getSubtotal());
        $this->assertNotNull($cart->find('SKU-1'));
    }

    public function testDiscountDefaultsToNothing(): void
    {
        $cart = new Cart(items: [new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1)]);

        $this->assertSame(0.0, $cart->getDiscount());
        $this->assertSame('', $cart->getCouponCode());
        $this->assertSame(10.0, $cart->getSubtotalAfterDiscount());
    }

    public function testSubtotalAfterDiscountTakesTheDiscountOff(): void
    {
        $cart = new Cart(
            items: [new CartItem(productId: 'SKU-1', title: 'Chair', price: 2395.0, quantity: 1)],
            currency: 'CAD',
            discount: 359.25,
            couponCode: 'SAVE15'
        );

        $this->assertSame(359.25, $cart->getDiscount());
        $this->assertSame('SAVE15', $cart->getCouponCode());
        $this->assertSame(2035.75, $cart->getSubtotalAfterDiscount());
    }

    public function testSubtotalAfterDiscountNeverGoesBelowZero(): void
    {
        $cart = new Cart(
            items: [new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1)],
            discount: 25.0
        );

        $this->assertSame(0.0, $cart->getSubtotalAfterDiscount());
    }

    public function testDiscountAndCouponRoundTripThroughArrays(): void
    {
        $cart = new Cart(
            items: [new CartItem(productId: 'SKU-1', title: 'Chair', price: 2395.0, quantity: 1)],
            currency: 'CAD',
            discount: 359.25,
            couponCode: 'SAVE15'
        );
        $array = $cart->toArray();

        $this->assertSame(359.25, $array['discount']);
        $this->assertSame('SAVE15', $array['coupon_code']);
        $this->assertSame(2035.75, $array['subtotal_after_discount']);

        $restored = Cart::fromArray($array);
        $this->assertSame(359.25, $restored->getDiscount());
        $this->assertSame('SAVE15', $restored->getCouponCode());
        $this->assertSame(2035.75, $restored->getSubtotalAfterDiscount());
    }
}
