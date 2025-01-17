<?php

namespace DoubleThreeDigital\SimpleCommerce\Http\Controllers;

use DoubleThreeDigital\SimpleCommerce\Events\PostCheckout;
use DoubleThreeDigital\SimpleCommerce\Events\PreCheckout;
use DoubleThreeDigital\SimpleCommerce\Exceptions\CheckoutProductHasNoStockException;
use DoubleThreeDigital\SimpleCommerce\Exceptions\CustomerNotFound;
use DoubleThreeDigital\SimpleCommerce\Exceptions\GatewayNotProvided;
use DoubleThreeDigital\SimpleCommerce\Exceptions\PreventCheckout;
use DoubleThreeDigital\SimpleCommerce\Facades\Coupon;
use DoubleThreeDigital\SimpleCommerce\Facades\Customer;
use DoubleThreeDigital\SimpleCommerce\Facades\Gateway;
use DoubleThreeDigital\SimpleCommerce\Http\Requests\AcceptsFormRequests;
use DoubleThreeDigital\SimpleCommerce\Http\Requests\Checkout\StoreRequest;
use DoubleThreeDigital\SimpleCommerce\Orders\Cart\Drivers\CartDriver;
use DoubleThreeDigital\SimpleCommerce\Rules\ValidCoupon;
use DoubleThreeDigital\SimpleCommerce\SimpleCommerce;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SitesSite;

class CheckoutController extends BaseActionController
{
    use CartDriver, AcceptsFormRequests;

    public $order;
    public StoreRequest $request;
    public $excludedKeys = ['_token', '_params', '_redirect', '_request'];

    public function __invoke(StoreRequest $request)
    {
        $this->order = $this->getCart();
        $this->request = $request;

        try {
            event(new PreCheckout($this->order, $this->request));

            $this
                ->handleAdditionalValidation()
                ->handleCustomerDetails()
                ->handleCoupon()
                ->handleStock()
                ->handleRemainingData()
                ->handlePayment()
                ->postCheckout();
        } catch (CheckoutProductHasNoStockException $e) {
            $lineItem = $this->order->lineItems()->filter(function ($lineItem) use ($e) {
                return $lineItem->product()->id() === $e->product->id();
            })->first();

            $this->order->removeLineItem($lineItem->id());
            $this->order->save();

            return $this->withErrors($this->request, __('Checkout failed. A product in your cart has no stock left. The product has been removed from your cart.'));
        } catch (PreventCheckout $e) {
            return $this->withErrors($this->request, $e->getMessage());
        }

        return $this->withSuccess($request, [
            'message' => __('Checkout Complete!'),
            'cart'    => $request->wantsJson()
                ? $this->order->toResource()
                : $this->order->toAugmentedArray(),
            'is_checkout_request' => true,
        ]);
    }

    protected function handleAdditionalValidation()
    {
        $rules = array_merge(
            $this->request->get('_request')
                ? $this->buildFormRequest($this->request->get('_request'), $this->request)->rules()
            : [],
        $this->request->has('gateway')
                ? Gateway::use($this->request->get('gateway'))->purchaseRules()
                : [],
            [
                'coupon' => ['nullable', new ValidCoupon($this->order)],
                'email' => ['nullable', 'email', function ($attribute, $value, $fail) {
                    if (preg_match('/^\S*$/u', $value) === 0) {
                        return $fail(__('Your email may not contain any spaces.'));
                    }
                }],
            ],
        );

        $messages = array_merge(
            $this->request->get('_request')
                ? $this->buildFormRequest($this->request->get('_request'), $this->request)->messages()
                : [],
            $this->request->has('gateway')
                ? Gateway::use($this->request->get('gateway'))->purchaseMessages()
                : [],
            [],
        );

        $this->request->validate($rules, $messages);

        return $this;
    }

    protected function handleCustomerDetails()
    {
        $customerData = $this->request->has('customer')
            ? $this->request->get('customer')
            : [];

        if (is_string($customerData)) {
            $this->order->customer($customerData);
            $this->order->save();

            $this->excludedKeys[] = 'customer';

            return $this;
        }

        if ($this->request->has('name') && $this->request->has('email')) {
            $customerData['name'] = $this->request->get('name');
            $customerData['email'] = $this->request->get('email');

            $this->excludedKeys[] = 'name';
            $this->excludedKeys[] = 'email';
        } elseif ($this->request->has('first_name') && $this->request->has('last_name') && $this->request->has('email')) {
            $customerData['first_name'] = $this->request->get('first_name');
            $customerData['last_name'] = $this->request->get('last_name');
            $customerData['email'] = $this->request->get('email');

            $this->excludedKeys[] = 'first_name';
            $this->excludedKeys[] = 'last_name';
            $this->excludedKeys[] = 'email';
        } elseif ($this->request->has('email')) {
            $customerData['email'] = $this->request->get('email');

            $this->excludedKeys[] = 'email';
        }

        if (isset($customerData['email'])) {
            try {
                $customer = Customer::findByEmail($customerData['email']);
            } catch (CustomerNotFound $e) {
                $customerItemData = [
                    'published' => true,
                ];

                if (isset($customerData['name'])) {
                    $customerItemData['name'] = $customerData['name'];
                }

                if (isset($customerData['first_name']) && isset($customerData['last_name'])) {
                    $customerItemData['first_name'] = $customerData['first_name'];
                    $customerItemData['last_name'] = $customerData['last_name'];
                }

                $customer = Customer::make()
                    ->email($customerData['email'])
                    ->data($customerItemData);

                $customer->save();
            }

            $customer
                ->merge(
                    Arr::only($customerData, config('simple-commerce.field_whitelist.customers'))
                )
                ->save();

            $this->order->customer($customer->id());
            $this->order->save();

            $this->order = $this->order->fresh();
        }

        $this->excludedKeys[] = 'customer';

        return $this;
    }

    protected function handleStock()
    {
        $this->order = app(Pipeline::class)
            ->send($this->order)
            ->through([
                \DoubleThreeDigital\SimpleCommerce\Orders\Checkout\HandleStock::class,
            ])
            ->thenReturn();

        return $this;
    }

    protected function handleCoupon()
    {
        if ($coupon = $this->request->get('coupon')) {
            $coupon = Coupon::findByCode($coupon);

            $this->order->coupon($coupon);
            $this->order->save();

            $this->excludedKeys[] = 'coupon';
        }

        return $this;
    }

    protected function handleRemainingData()
    {
        $data = [];

        foreach (Arr::except($this->request->all(), $this->excludedKeys) as $key => $value) {
            if ($value === 'on') {
                $value = true;
            } elseif ($value === 'off') {
                $value = false;
            }

            $data[$key] = $value;
        }

        if ($data !== []) {
            $this->order->merge(Arr::only($data, config('simple-commerce.field_whitelist.orders')))->save();
            $this->order->save();

            $this->order = $this->order->fresh();
        }

        return $this;
    }

    protected function handlePayment()
    {
        $this->order = $this->order->recalculate();

        if ($this->order->grandTotal() <= 0) {
            $this->order->markAsPaid();

            return $this;
        }

        if (! $this->request->has('gateway') && $this->order->isPaid() === false && $this->order->grandTotal() !== 0) {
            throw new GatewayNotProvided('No gateway provided.');
        }

        $purchase = Gateway::use($this->request->gateway)->purchase($this->request, $this->order);

        $this->excludedKeys[] = 'gateway';

        foreach (Gateway::use($this->request->gateway)->purchaseRules() as $key => $rule) {
            $this->excludedKeys[] = $key;
        }

        $this->order->fresh();

        return $this;
    }

    protected function postCheckout()
    {
        if (! isset(SimpleCommerce::customerDriver()['model']) && $this->order->customer()) {
            $this->order->customer()->merge([
                'orders' => $this->order->customer()->orders()
                    ->pluck('id')
                    ->push($this->order->id())
                    ->toArray(),
            ]);

            $this->order->customer()->save();
        }

        if (! $this->request->has('gateway') && $this->order->isPaid() === false && $this->order->grandTotal() === 0) {
            $this->order->markAsPaid();
        }

        if ($this->order->coupon()) {
            $this->order->coupon()->redeem();
        }

        $this->forgetCart();

        event(new PostCheckout($this->order, $this->request));

        return $this;
    }

    protected function guessSiteFromRequest(): SitesSite
    {
        if ($site = request()->get('site')) {
            return Site::get($site);
        }

        foreach (Site::all() as $site) {
            if (Str::contains(request()->url(), $site->url())) {
                return $site;
            }
        }

        if ($referer = request()->header('referer')) {
            foreach (Site::all() as $site) {
                if (Str::contains($referer, $site->url())) {
                    return $site;
                }
            }
        }

        return Site::current();
    }
}
