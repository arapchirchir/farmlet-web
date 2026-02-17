@extends('layouts.app')

@section('header-title', __('Create New Driver'))

@section('content')
    <div class="page-title">
        <div class="d-flex gap-2 align-items-center">
            <i class="fa-solid fa-user"></i>{{ __('Create New Driver') }}
        </div>
    </div>

    <form action="{{ route('admin.rider.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-12 mx-auto">
                <div class="card mt-3">
                    <div class="card-body">

                        <div class="d-flex gap-2 border-bottom pb-2">
                            <i class="fa-solid fa-user"></i>
                            <h5>
                                {{ __('User Information') }}
                            </h5>
                        </div>

                        <div class="row">
                            <div class="col-lg-7">
                                <div class="row">
                                    <div class="col-lg-6 mt-3">
                                        <x-input label="First Name" name="first_name" type="text"
                                            placeholder="Enter first name" required="true" />
                                    </div>

                                    <div class="col-lg-6 mt-3">
                                        <x-input label="Last Name" name="last_name" type="text"
                                            placeholder="Enter last name" />
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <x-input label="Phone Number" name="phone" type="number"
                                        placeholder="Enter phone number" required="true" />
                                </div>

                                <div class="mt-3">
                                    <x-input type="email" name="email" label="Email"
                                        placeholder="Enter Email Address" />
                                </div>

                                <div class="mt-3">
                                    <x-select label="Gender" name="gender">
                                        <option value="male">{{ __('Male') }}</option>
                                        <option value="female">{{ __('Female') }}</option>
                                        <option value="other">{{ __('Other') }}</option>
                                    </x-select>
                                </div>

                                <div class="mt-3">
                                    <x-select
                                        label="{{ __('County') }}"
                                        name="county_id"
                                        required="true"
                                        data-location="county"
                                        data-subcounties-url="{{ route('admin.locations.subcounties') }}"
                                        data-wards-url="{{ route('admin.locations.wards') }}"
                                    >
                                        <option value="">{{ __('Select County') }}</option>
                                        @foreach ($counties as $county)
                                            <option value="{{ $county->id }}" {{ old('county_id') == $county->id ? 'selected' : '' }}>
                                                {{ $county->name }}
                                            </option>
                                        @endforeach
                                    </x-select>
                                </div>

                                <div class="mt-3">
                                    <x-select
                                        label="{{ __('Sub-County') }}"
                                        name="subcounty_id"
                                        required="true"
                                        data-location="subcounty"
                                        data-default-option="{{ __('Select Sub-County') }}"
                                        data-selected="{{ old('subcounty_id') }}"
                                    >
                                        <option value="">{{ __('Select Sub-County') }}</option>
                                    </x-select>
                                </div>

                                <div class="mt-3">
                                    <x-select
                                        label="{{ __('Ward') }}"
                                        name="ward_id"
                                        data-location="ward"
                                        data-default-option="{{ __('Select Ward') }}"
                                        data-selected="{{ old('ward_id') }}"
                                    >
                                        <option value="">{{ __('Select Ward') }}</option>
                                    </x-select>
                                </div>

                                <div class="mt-3">
                                    <x-input type="text" name="driving_lience" label="Driving License"
                                        placeholder="Enter License" />
                                </div>

                                <div class="mt-3">
                                    <x-input type="file" name="driving_license_proof" label="License / ID Proof"
                                        required="true" />
                                    <small class="text-muted d-block">
                                        {{ __('Supported formats: jpg, jpeg, png, pdf (max 4 MB)') }}
                                    </small>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mt-3">
                                        <x-input type="text" name="password" label="Password"
                                            placeholder="Enter Password" required="true" />
                                    </div>
                                    <div class="col-md-6 mt-3">
                                        <x-input type="text" name="password_confirmation" label="Confirm Password"
                                            required="true" placeholder="Enter Confirm Password" />
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5">

                                <div>
                                    <h5>
                                        {{ __('Profile Photo ') }}
                                        <span class="text-primary bg-light">Ratio 1:1 (500 x 500 px)</span>
                                        <span class="text-danger">*</span>
                                    </h5>
                                    @error('profile_photo')
                                        <p class="text-danger">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="dropzone-container">
                                    <label for="thumbnail" class="mainThumbnail">
                                        <img src="https://placehold.co/500x500/png" id="preview" alt=""
                                            width="50%" class="dropzone-area">
                                    </label>
                                    <input id="thumbnail" accept="image/*" type="file" data-crop="true"
                                        name="profile_photo" class="d-none" onchange="previewFile(event, 'preview')"
                                        data-preview="preview" data-width="500" data-height="500">
                                    <small class="text-muted d-block">
                                        {{ __('Supported formats: jpg, jpeg, png') }}
                                    </small>
                                </div>

                                <div class="mt-3">
                                    <x-input type="date" name="date_of_birth" label="Date of Birth" />
                                </div>

                                <div class="mt-3">
                                    <x-input type="text" name="vehicle_type" label="Vehicle Type"
                                        placeholder="Enter Vehicle Type" required="true" />
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary py-2.5 px-5">
                                {{ __('Submit') }}
                            </button>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </form>
@endsection

@push('scripts')
    @include('partials.location-dependent')
@endpush
