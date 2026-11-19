{{--
    The Niskala flower. Drawn in the current text colour (the PNG is used as
    a mask), so it always matches the wordmark next to it:

    <x-brand.logo class="size-12 text-gold-500" />

    Decorative: the NISKALA wordmark beside it carries the name.
--}}
<span
    {{ $attributes->class('brand-logo') }}
    style="--brand-logo: url('{{ asset('images/brand/niskala-logo.png') }}')"
    aria-hidden="true"
></span>
