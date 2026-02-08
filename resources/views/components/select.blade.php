<div>
    @if (isset($label) && $label)
        <label for="{{ $name }}" class="form-label">
            {{ __($label) }}
            @if($required) <span class="text-danger">*</span> @endif
        </label>
    @endif
    <select
        @if ($placeholder) data-placeholder="{{ $placeholder }}" @endif
        {{ $attributes->merge([
            'name' => $name,
            'id' => $id ?? $name,
            'class' => 'form-control select2 ' . ($errors->has($name) ? 'is-invalid' : ''),
            'style' => 'width: 100%;',
        ]) }}
        @if ($multiselect) multiple @endif
    >
        {{ $slot }}
    </select>
    @error($name)
        <p class="text text-danger m-0">{{ $message }}</p>
    @enderror
</div>
