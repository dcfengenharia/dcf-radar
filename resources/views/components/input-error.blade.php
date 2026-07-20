@props(['for' => null, 'messages' => []])

@if ($for)
    @error($for)
        <span {{ $attributes->merge(['class' => 'invalid-feedback']) }} role="alert">
            <span class="fw-medium">{{ $message }}</span>
        </span>
    @enderror
@elseif (!empty($messages))
    @foreach ($messages as $message)
        <p {{ $attributes->merge(['class' => 'text-sm text-red-600 mt-2']) }}>{{ $message }}</p>
    @endforeach
@endif
