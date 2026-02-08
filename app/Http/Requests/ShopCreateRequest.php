<?php

namespace App\Http\Requests;

use App\Models\Subcounty;
use App\Models\Ward;
use App\Models\VerifyManage;
use App\Rules\EmailRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;

class ShopCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = null;
        $required = 'required';
        if ($this->routeIs('admin.shop.update')) {
            $user = $this->shop?->user;
            $required = 'nullable';
        }
        $isSellerRegister = $this->routeIs('seller.register', 'admin.shop.store', 'shop.register.submit');
        $locationRequired = $isSellerRegister ? 'required' : 'nullable';
        $sellerTypeRequired = $isSellerRegister ? 'required' : 'nullable';

        $verifyManage = Cache::rememberForever('verify_manage', function () {
            return VerifyManage::first();
        });

        $phoneRequired = $verifyManage?->phone_required ? 'required' : 'nullable';
        $phoneRequired = $verifyManage ? $phoneRequired : 'required';

        $min = $verifyManage?->phone_min_length ?? 9;
        $max = $verifyManage?->phone_max_length ?? 16;

        $phoneValidate = [$phoneRequired, 'min_digits:'.$min, 'max_digits:'.$max, 'unique:users,phone,'.$user?->id];

        // validation rules
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => $phoneValidate,
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user?->id, new EmailRule],
            'Gender' => ['nullable', 'string'],
            'password' => [$required, 'min:6', 'confirmed'],
            'password_confirmation' => [$required, 'min:6'],
            'address' => ['nullable', 'string'],
            'profile_photo' => [$required, 'image', 'mimes:jpg,png,jpeg,gif', 'max:10240'],
            'shop_name' => ['required', 'string', 'max:100'],
            'shop_logo' => [$required, 'max:10240'],
            'shop_banner' => [$required, 'max:10240'],
            'description' => ['nullable', 'string', 'max:200'],
            'seller_type' => [$sellerTypeRequired, 'in:vendor,farmer'],
            'processing_supported' => ['nullable', 'boolean'],
            'county_id' => [$locationRequired, 'exists:counties,id'],
            'subcounty_id' => [$locationRequired, 'exists:subcounties,id'],
            'ward_id' => [$locationRequired, 'exists:wards,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $countyId = $this->input('county_id');
            $subcountyId = $this->input('subcounty_id');
            $wardId = $this->input('ward_id');

            if ($countyId && $subcountyId) {
                $subcounty = Subcounty::find($subcountyId);
                if ($subcounty && $subcounty->county_id != $countyId) {
                    $validator->errors()->add('subcounty_id', __('The selected sub-county does not belong to the selected county.'));
                }
            }

            if ($subcountyId && $wardId) {
                $ward = Ward::find($wardId);
                if ($ward && $ward->subcounty_id != $subcountyId) {
                    $validator->errors()->add('ward_id', __('The selected ward does not belong to the selected sub-county.'));
                }
            }
        });
    }

    public function messages(): array
    {
        $request = request();
        if ($request->is('api/*')) {
            $header = strtolower($request->header('accept-language'));
            $lan = (preg_match('/^[a-z]+$/', $header)) ? $header : 'en';
            app()->setLocale($lan);
        }

        return [
            'first_name.required' => __('The first name field is required.'),
            'phone.required' => __('The phone field is required.'),
            'phone.unique' => __('The phone has already been taken.'),
            'email.required' => __('The email field is required.'),
            'email.unique' => __('The email has already been taken.'),
            'password.required' => __('The password field is required.'),
            'password.min' => __('The password must be at least 6 characters.'),
            'password.confirmed' => __('The password and confirmation password do not match.'),
            'profile_photo.image' => __('The profile photo must be an image.'),
            'profile_photo.max' => __('The profile photo must not be greater than 2 MB.'),
            'shop_name.required' => __('The shop name field is required.'),
            'shop_logo.image' => __('The shop logo must be an image.'),
            'shop_logo.max' => __('The shop logo must not be greater than 2 MB.'),
            'shop_banner.image' => __('The shop banner must be an image.'),
            'shop_banner.max' => __('The shop banner must not be greater than 2 MB.'),
            'description.max' => __('The description may not be greater than 200 characters.'),
            'password_confirmation.min' => __('The password confirmation must be at least 6 characters.'),
            'password_confirmation.required' => __('The password confirmation field is required.'),
            'address.max' => __('The address may not be greater than 255 characters.'),
        ];
    }
}
