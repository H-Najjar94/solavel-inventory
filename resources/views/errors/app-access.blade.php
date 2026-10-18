{{-- Back-compat alias: CentralAppAccess/AppAccessDecision now render errors.403 / errors.503 directly. --}}
@if(($reason ?? null) === 'temporarily_unavailable')
@include('errors._branded-status', ['code' => 503, 'variant' => 'access_check'])
@else
@include('errors._branded-403')
@endif
