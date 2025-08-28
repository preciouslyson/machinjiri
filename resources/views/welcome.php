<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Machinjiri</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; line-height: 1.6; background: #f4f4f4; color: #333; }
    header, footer { background: #222; color: #fff; padding: 20px 0; text-align: center; }
    .container { max-width: 960px; margin: auto; padding: 20px; }
    .hero { text-align: center; padding: 80px 20px; background: #eaeaea; }
    .hero h1 { font-size: 3em; margin-bottom: 10px; }
    .hero p { font-size: 1.2em; margin-bottom: 20px; }
    .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: #fff; text-decoration: none; border-radius: 4px; }
    .features { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 40px; }
    .feature { flex: 1; min-width: 280px; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .feature h3 { margin-bottom: 10px; }
    .cta { text-align: center; margin: 60px 0; }
    @media (max-width: 600px) {
      .hero h1 { font-size: 2em; }
      .features { flex-direction: column; }
    }
  </style>
</head>
<body>

  <header>
    <div class="container">
      <h2>Mlangeni Framework</h2>
      <p>Built for clarity, speed, and scale</p>
    </div>
  </header>

  <section class="hero">
    <div class="container">
      <h1>Welcome to Mlangeni</h1>
      <p>A modular PHP framework designed for developers who demand precision and performance.</p>
      <a href="#features" class="btn">Explore Features</a>
    </div>
  </section>

  <section class="container" id="features">
    <div class="features">
      <div class="feature">
        <h3>⚙️ Modular Design</h3>
        <p>Each class lives in its own namespace and folder. Clean, scalable, and PSR-4 compliant.</p>
      </div>
      <div class="feature">
        <h3>🔌 API Integration</h3>
        <p>Built-in support for RESTful routing, request/response handling, and real-world APIs.</p>
      </div>
      <div class="feature">
        <h3>📦 Composer Autoloading</h3>
        <p>Autoloading and dependency management with zero configuration friction.</p>
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="container">
      <h2>Ready to build?</h2>
      <p>Start your next PHP project with a framework that’s built for maintainability and scale.</p>
      <a href="/docs" class="btn">Read the Docs</a>
    </div>
  </section>

  <footer>
    <div class="container">
      <p>&copy; <?= date('Y') ?> Mlangeni Technologies. All rights reserved.</p>
    </div>
  </footer>

</body>
</html>