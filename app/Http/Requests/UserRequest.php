<?php

namespace App\Http\Requests;

use App\Models\Subcounty;
use App\Models\Ward;
use App\Rules\EmailRule;
use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
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
        $routeUser = $this->route('user');
        $userId = $routeUser?->id ?? auth()->id();
        $isEmployeeManageRoute = $this->routeIs('admin.employee.store', 'admin.employee.update');
        $isAdminEmployeeStore = $this->routeIs('admin.employee.store');
        $roleRequired = $isAdminEmployeeStore ? 'required' : 'nullable';
        $targetRole = $this->input('role') ?: $routeUser?->getRoleNames()->first();
        $locationRequired = $isEmployeeManageRoute && $targetRole === 'processing_manager' ? 'nullable' : 'nullable';

        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users,phone,' . $userId,
            'email' => ['nullable', 'email', 'max:255', new EmailRule, 'unique:users,email,' . $userId],
            'profile_photo' => 'nullable|image|max:2048|mimes:png,jpg,jpeg,gif',
            'role' => [$roleRequired, 'exists:roles,name'],
            'county_id' => [$locationRequired, 'exists:counties,id'],
            'subcounty_id' => [$locationRequired, 'exists:subcounties,id'],
            'ward_id' => ['nullable', 'exists:wards,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (! $this->routeIs('admin.employee.store', 'admin.employee.update')) {
                return;
            }

            $routeUser = $this->route('user');
            $targetRole = $this->input('role') ?: $routeUser?->getRoleNames()->first();

            if ($targetRole !== 'processing_manager') {
                return;
            }

            $countyId = $this->filled('county_id') ? $this->input('county_id') : $routeUser?->county_id;
            $subcountyId = $this->filled('subcounty_id') ? $this->input('subcounty_id') : $routeUser?->subcounty_id;
            $wardId = $this->filled('ward_id') ? $this->input('ward_id') : $routeUser?->ward_id;

            if (! $countyId) {
                $validator->errors()->add('county_id', __('The county field is required.'));
            }

            if (! $subcountyId) {
                $validator->errors()->add('subcounty_id', __('The sub-county field is required.'));
            }

            if ($subcountyId && ! $countyId) {
                $validator->errors()->add('county_id', __('County is required when sub-county is selected.'));
            }

            if ($wardId && ! $subcountyId) {
                $validator->errors()->add('subcounty_id', __('Sub-county is required when ward is selected.'));
            }

            if ($subcountyId) {
                $subcounty = Subcounty::find($subcountyId);
                if (! $subcounty) {
                    $validator->errors()->add('subcounty_id', __('The selected sub-county is invalid.'));
                } elseif ($countyId && (int) $subcounty->county_id !== (int) $countyId) {
                    $validator->errors()->add('subcounty_id', __('The selected sub-county does not belong to the selected county.'));
                }
            }

            if ($wardId) {
                $ward = Ward::find($wardId);
                if (! $ward) {
                    $validator->errors()->add('ward_id', __('The selected ward is invalid.'));
                } elseif ($subcountyId && (int) $ward->subcounty_id !== (int) $subcountyId) {
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
            'name.required' => __('The name field is required.'),
            'email.email' => __('The email must be a valid email address.'),
            'profile_photo.image' => __('The profile photo must be an image.'),
            'profile_photo.max' => __('The profile photo must not be greater than 2 MB.'),
            'phone.required' => __('The phone field is required.'),
            'phone.unique' => __('The phone has already been taken.'),
            'email.unique' => __('The email has already been taken.'),
            'role.required' => __('The role field is required.'),
            'role.exists' => __('The selected role is invalid.'),
            'county_id.required' => __('The county field is required.'),
            'subcounty_id.required' => __('The sub-county field is required.'),
            'profile_photo.mimes' => __('The profile photo must be a file of type: jpg, jpeg, png, svg, webp, gif.'),
        ];
    }
}
