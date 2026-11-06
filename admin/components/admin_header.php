<header>
  <div class="container" style="display:flex;align-items:center;gap:5px;">
  <a href="/" title="<?=htmlspecialchars($site_name)?> Home" aria-label="Go to <?=htmlspecialchars($site_name)?> homepage" class="brand-link">
  <img src="/assets/img/logo.webp" alt="<?=htmlspecialchars($site_name)?> Logo" width="320" height="320" loading="lazy" decoding="async">
  <p class="brand-title"><?=htmlspecialchars($site_name)?></p>
</a>
<nav aria-label="Main Menu">
  <ul>
    <li><a href="/admin/dashboard.php">Home</a></li>
<li><a href="#" class="dark-mode-toggle">Dark Mode</a></li>
  </ul>
</nav>
<button class="menu-toggle" id="menuToggle" aria-label="Open Menu">&#9776;</button>
  </div>
</header>
<div class="overlay" id="overlay" role="presentation"></div>
<aside class="sidebar" id="sidebar" aria-label="Sidebar Navigation">
  <div class="sidebar-header">
    <h2>Menu</h2>
    <button class="close-btn" id="closeBtn" aria-label="Close Menu">&#10005;</button>
  </div>
  <nav role="navigation">
  </nav>
</aside>
<script id="msb">const m=document.getElementById("menuToggle"), c=document.getElementById("closeBtn"), s=document.getElementById("sidebar"), o=document.getElementById("overlay"), h=document.querySelector("header nav ul"), n=document.querySelector("#sidebar nav"); if(h&&n&&!n.children.length)n.innerHTML=`<ul>${h.innerHTML}</ul>`; const t=()=>{s.classList.toggle("active"),o.classList.toggle("active"),m.setAttribute("aria-expanded",s.classList.contains("active"))}; [m,c,o].forEach(e=>e&&e.addEventListener("click",t));</script>