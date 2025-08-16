<?php use Mlangeni\Machinjiri\Core\Views\View; ?>

<?php View::extend('layouts/main'); ?>

<?php View::section('content'); ?>
<div class="container mt-3">
  <div class="p-5 mb-4 bg-light shadow rounded-3">
    <div class="container-fluid">
      <h1 class="display-5 fw-bold text-danger border-bottom border-danger">Machinjiri Framework</h1>
      <p class="col-md-8 fs-4">This is a full dynamic PHP Framework for building robust applications</p>
      <button class="btn btn-primary btn-lg" type="button">Learn more</button>
    </div>
  </div>
</div>
<?php View::endsection(); ?>

