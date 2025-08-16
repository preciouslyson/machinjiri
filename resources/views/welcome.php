<?php use Mlangeni\Machinjiri\Core\Views\View; ?>

<?php View::extend('layouts/main'); ?>

<?php View::section('content'); ?>
<section>
  <nav class="navbar navbar-expand navbar-dark text-bg-dark">
    <div class="container">
      <a class="navbar-brand" href="#">Machinjiri</a>
      <div class="navbar-nav">
        <a class="nav-link" href="#">About</a>
        <a class="nav-link" href="#">Documentation</a>
        <a class="nav-link" href="#">Contact</a>
      </div>
    </div>
  </nav>
</section>
<section class="text-bg-secondary">
  <div class="container">
  <div class="mb-4 py-4">
    <h1 class="display-5 fw-bold">Welcome to Mlangeni Technologies</h1>
      <p class="col-md-8 fs-4">We build scalable, modular PHP frameworks with precision and clarity. Explore our plugins, APIs, and developer-friendly architecture.</p>
    <button class="btn btn-primary btn-lg" type="button">Learn More</button>
  </div>
</div>
</section>
<?php View::endsection(); ?>