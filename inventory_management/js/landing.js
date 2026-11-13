document.addEventListener("DOMContentLoaded", function () {
  var modal = document.getElementById("infoModal");
  var content = document.getElementById("infoContent");
  var close = document.getElementById("infoClose");

  var data = {
    about: `
      <div class="info-head">
        <div class="info-icon">◆</div>
        <div>
          <span class="info-label">INVENTORY MANAGEMENT SYSTEM</span>
          <h2>About Us</h2>
        </div>
      </div>
      <p class="about-text">We are a dedicated team focused on building an efficient and reliable inventory management system to help businesses track stock, manage warehouses, monitor requests, and keep deliveries organized in one place.</p>
      <div class="about-grid">
        <div class="about-card"><b>Inventory Tracking</b><span>Monitor items and stock movement clearly.</span></div>
        <div class="about-card"><b>Warehouse Management</b><span>Keep warehouse records organized and easy to manage.</span></div>
        <div class="about-card"><b>Request Management</b><span>Handle requests from other teams and update their status.</span></div>
        <div class="about-card"><b>Delivery Progress</b><span>Track requests from To Ship to Delivered.</span></div>
      </div>`,
    contact: `
      <div class="info-head">
        <div class="info-icon">☎</div>
        <div>
          <span class="info-label">GET IN TOUCH</span>
          <h2>Contact Us</h2>
        </div>
      </div>
      <p class="about-text">For questions, concerns, or system-related matters, you may contact our team through the details below.</p>
      <div class="contact-list">
        <div class="contact-person"><div class="person-icon">A</div><div><b>Andrew C. Suficiencia</b><span>andrewsuficiencia09@gmail.com</span><span>Contact Number: 09462316228</span></div></div>
        <div class="contact-person"><div class="person-icon">F</div><div><b>Ferdausi Banisil</b><span>ferdausi.banisil@gmail.com</span><span>Contact Number: 09660254318</span></div></div>
        <div class="contact-person"><div class="person-icon">M</div><div><b>Meynard Paje</b><span>pajemeynard@gmail.com</span><span>Contact Number: 09927099656</span></div></div>
      </div>`,
  };

  document.querySelectorAll(".landing-link").forEach(function (button) {
    button.addEventListener("click", function () {
      content.innerHTML = data[button.dataset.info];
      modal.classList.add("show");
      content.classList.remove("info-pop");
      void content.offsetWidth;
      content.classList.add("info-pop");
    });
  });

  close.addEventListener("click", function () {
    modal.classList.remove("show");
  });

  modal.addEventListener("click", function (event) {
    if (event.target === modal) modal.classList.remove("show");
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") modal.classList.remove("show");
  });
});
