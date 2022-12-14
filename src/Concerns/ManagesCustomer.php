<?php

namespace Laravel\Cashier\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\InvalidRequestException as StripeInvalidRequestException;

trait ManagesCustomer
{
    /**
     * Retrieve the Stripe customer ID.
     *
     * @return string|null
     */
    public function stripeId()
    {
        return $this->stripe_id;
    }

    /**
     * Determine if the customer has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        return ! is_null($this->stripe_id);
    }

    /**
     * Determine if the customer has a Stripe customer ID and throw an exception if not.
     *
     * @return void
     *
     * @throws \Laravel\Cashier\Exceptions\InvalidCustomer
     */
    protected function assertCustomerExists()
    {
        if (! $this->hasStripeId()) {
            throw InvalidCustomer::notYetCreated($this);
        }
    }

    /**
     * Create a Stripe customer for the given model.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     *
     * @throws \Laravel\Cashier\Exceptions\CustomerAlreadyCreated
     */
    public function createAsStripeCustomer(array $options = [], array $stripeOptions = [])
    {
        if ($this->hasStripeId()) {
            throw CustomerAlreadyCreated::exists($this);
        }

        if (! array_key_exists('name', $options) && $name = $this->stripeName()) {
            $options['name'] = $name;
        }

        if (! array_key_exists('email', $options) && $email = $this->stripeEmail()) {
            $options['email'] = $email;
        }

        if (! array_key_exists('phone', $options) && $phone = $this->stripePhone()) {
            $options['phone'] = $phone;
        }

        if (! array_key_exists('address', $options) && $address = $this->stripeAddress()) {
            $options['address'] = $address;
        }

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = $this->stripe($stripeOptions)->customers->create($options);

        $this->stripe_id = $customer->id;

        $this->save();

        return $customer;
    }

    /**
     * Update the underlying Stripe customer information for the model.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function updateStripeCustomer(array $options = [], array $stripeOptions = [])
    {
        return $this->stripe($stripeOptions)->customers->update(
            $this->stripe_id, $options
        );
    }

    /**
     * Get the Stripe customer instance for the current user or create one.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createOrGetStripeCustomer(array $options = [], array $stripeOptions = [])
    {
        if ($this->hasStripeId()) {
            return $this->asStripeCustomer([], $stripeOptions);
        }

        return $this->createAsStripeCustomer($options, $stripeOptions);
    }

    /**
     * Get the Stripe customer for the model.
     *
     * @param  array  $expand
     * @return \Stripe\Customer
     */
    public function asStripeCustomer(array $expand = [], array $stripeOptions = [])
    {
        $this->assertCustomerExists();
        
        return $this->stripe($stripeOptions)->customers->retrieve(
            $this->stripe_id, ['expand' => $expand]
        );
    }

    /**
     * Get the name that should be synced to Stripe.
     *
     * @return string|null
     */
    public function stripeName()
    {
        return $this->name;
    }

    /**
     * Get the email address that should be synced to Stripe.
     *
     * @return string|null
     */
    public function stripeEmail()
    {
        return $this->email;
    }

    /**
     * Get the phone number that should be synced to Stripe.
     *
     * @return string|null
     */
    public function stripePhone()
    {
        return $this->phone;
    }

    /**
     * Get the address that should be synced to Stripe.
     *
     * @return array|null
     */
    public function stripeAddress()
    {
        // return [
        //     'city' => 'Little Rock',
        //     'country' => 'US',
        //     'line1' => '1 Main St.',
        //     'line2' => 'Apartment 5',
        //     'postal_code' => '72201',
        //     'state' => 'Arkansas',
        // ];
    }

    /**
     * Sync the customer's information to Stripe.
     *
     * @return \Stripe\Customer
     */
    public function syncStripeCustomerDetails()
    {
        return $this->updateStripeCustomer([
            'name' => $this->stripeName(),
            'email' => $this->stripeEmail(),
            'phone' => $this->stripePhone(),
            'address' => $this->stripeAddress(),
        ]);
    }

    /**
     * Apply a coupon to the customer.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $this->assertCustomerExists();

        $this->updateStripeCustomer([
            'coupon' => $coupon,
        ]);
    }

    /**
     * Get the Stripe supported currency used by the customer.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return config('cashier.currency');
    }

    /**
     * Get the Stripe billing portal for this customer.
     *
     * @param  string|null  $returnUrl
     * @param  array  $options
     * @return string
     */
    public function billingPortalUrl($returnUrl = null, array $options = [])
    {
        $this->assertCustomerExists();

        return $this->stripe()->billingPortal->sessions->create(array_merge([
            'customer' => $this->stripeId(),
            'return_url' => $returnUrl ?? route('home'),
        ], $options))['url'];
    }

    /**
     * Generate a redirect response to the customer's Stripe billing portal.
     *
     * @param  string|null  $returnUrl
     * @param  array  $options
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirectToBillingPortal($returnUrl = null, array $options = [])
    {
        return new RedirectResponse(
            $this->billingPortalUrl($returnUrl, $options)
        );
    }

    /**
     * Get a collection of the customer's TaxID's.
     *
     * @return \Illuminate\Support\Collection|\Stripe\TaxId[]
     */
    public function taxIds(array $options = [])
    {
        $this->assertCustomerExists();

        return new Collection(
            $this->stripe()->customers->allTaxIds($this->stripe_id, $options)->data
        );
    }

    /**
     * Find a TaxID by ID.
     *
     * @return \Stripe\TaxId|null
     */
    public function findTaxId($id)
    {
        $this->assertCustomerExists();

        try {
            return $this->stripe()->customers->retrieveTaxId(
                $this->stripe_id, $id, []
            );
        } catch (StripeInvalidRequestException $exception) {
            //
        }
    }

    /**
     * Create a TaxID for the customer.
     *
     * @param  string  $type
     * @param  string  $value
     * @return \Stripe\TaxId
     */
    public function createTaxId($type, $value)
    {
        $this->assertCustomerExists();

        return $this->stripe()->customers->createTaxId($this->stripe_id, [
            'type' => $type,
            'value' => $value,
        ]);
    }

    /**
     * Delete a TaxID for the customer.
     *
     * @param  string  $id
     * @return void
     */
    public function deleteTaxId($id)
    {
        $this->assertCustomerExists();

        try {
            $this->stripe()->customers->deleteTaxId($this->stripe_id, $id);
        } catch (StripeInvalidRequestException $exception) {
            //
        }
    }

    /**
     * Determine if the customer is not exempted from taxes.
     *
     * @return bool
     */
    public function isNotTaxExempt()
    {
        return $this->asStripeCustomer()->tax_exempt === StripeCustomer::TAX_EXEMPT_NONE;
    }

    /**
     * Determine if the customer is exempted from taxes.
     *
     * @return bool
     */
    public function isTaxExempt()
    {
        return $this->asStripeCustomer()->tax_exempt === StripeCustomer::TAX_EXEMPT_EXEMPT;
    }

    /**
     * Determine if reverse charge applies to the customer.
     *
     * @return bool
     */
    public function reverseChargeApplies()
    {
        return $this->asStripeCustomer()->tax_exempt === StripeCustomer::TAX_EXEMPT_REVERSE;
    }

    /**
     * Get the Stripe SDK client.
     *
     * @param  array  $options
     * @return \Stripe\StripeClient
     */
    public static function stripe(array $options = [])
    {
        return Cashier::stripe($options);
    }
}
