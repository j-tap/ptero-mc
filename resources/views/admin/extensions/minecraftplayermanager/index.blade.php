@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "minecraftplayermanager";
    $EXTENSION_NAME = stripslashes("Minecraft Player Manager");
    $EXTENSION_VERSION = "1.4.2";
    $EXTENSION_DESCRIPTION = stripslashes("Manage Minecraft Java Players from your Panel Interface.");
    $EXTENSION_ICON = "/assets/extensions/minecraftplayermanager/icon.jpg";
    $EXTENSION_WEBSITE = "[website]";
    $EXTENSION_WEBICON = "[webicon]";
?>
@include('blueprint.admin.template')

@section('title')
    {{ $EXTENSION_NAME }}
@endsection

@section('content-header')
    @yield('extension.header')
@endsection

@section('content')
    @yield('extension.config')
    @yield('extension.description')<?php
  $id = 6;

  $response = cache()->remember('product-' . $id, 30 * 60, function () use ($id) {
    return @file_get_contents("https://products.rjns.dev/api/products/{$id}", false, stream_context_create([
      'http' => [
        'timeout' => 1
      ]
    ]));
  });

  if ($response === FALSE) {
    $version = 'Unknown';
    $providers = [];
  } else {
    $data = json_decode($response, true);

    $version = $data['product']['version'];
    $providers = array_values($data['providers']);
  }

  $nonceIdentifier = '157c596bbaff828db2827eef1a2882c2';
  $nonceIdentifierWithoutReplacement = '%%__NONCE' . '__%%';
?>

<div class="row">
  <div class="col-lg-3 col-md-3 col-sm-12 col-xs-12">
    <div class="box {{ $version !== 'Unknown' ? $version !== "1.4.2" ? 'box-danger' : 'box-primary' : 'box-primary' }}">
      <div class="box-header with-border">
        <h3 class="box-title"><i class='bx bx-git-repo-forked' ></i> Information</h3>
      </div>
      <div class="box-body">
        <p>
          Thank you for purchasing <b>Minecraft Player Manager</b>! You are currently using version <code>1.4.2</code> (latest version is <code>{{ $version }}</code>).
          If you have any questions or need help, please visit our <a href="https://rjansen.dev/discord" target="_blank">Discord</a>.
          <b>{{ $nonceIdentifier === $nonceIdentifierWithoutReplacement ? "This is an indev version of the product!" : "" }}</b>
        </p>

        <div class="row" style="margin-top: 10px;">
          @foreach ($providers as $provider)
            <div class="col-md-6">
              <a href="{{ $provider['link'] }}" target="_blank" class="btn btn-primary btn-block"><i class='bx bx-store'></i> {{ $provider['name'] }}</a>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-9 col-md-9 col-sm-12 col-xs-12">
    <div class="box">
      <div class="box-header with-border">
        <h3 class="box-title"><i class='bx bxs-info-square'></i> Banner</h3>
      </div>
      <div class="box-body">
        <img src="/extensions/minecraftplayermanager/minecraftplayermanager_banner.jpg" class="img-rounded img-responsive" alt="Banner" style="max-width: 600px; margin: 0 auto;">
      </div>
    </div>
  </div>
</div>
@endsection
