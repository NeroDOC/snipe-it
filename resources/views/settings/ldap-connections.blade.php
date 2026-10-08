@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.ldap_ad') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <div class="row">
        <div class="col-sm-10 col-sm-offset-1 col-md-10 col-md-offset-1">
            <livewire:ldap-connections />
        </div>
    </div>
@stop
