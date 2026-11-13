(function () {
  function request(url, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        var r = { success: false, message: "Server error" };
        try {
          r = JSON.parse(xhr.responseText);
        } catch (e) {}
        callback(r, xhr.status);
      }
    };
    var body = [];
    for (var k in data) {
      body.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k]));
    }
    xhr.send(body.join("&"));
  }
  var form = document.getElementById("loginForm");
  document.getElementById("togglePassword").onclick = function () {
    var p = document.getElementById("password");
    p.type = p.type === "password" ? "text" : "password";
  };
  var remembered = localStorage.getItem("inventory_username");
  if (remembered) {
    document.getElementById("username").value = remembered;
    document.getElementById("remember").checked = true;
  }
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var msg = document.getElementById("loginMessage");
    msg.className = "message";
    msg.textContent = "Signing in...";
    var data = {
      username: document.getElementById("username").value,
      password: document.getElementById("password").value,
    };
    request("php/login.php", data, function (r) {
      if (r.success) {
        if (document.getElementById("remember").checked)
          localStorage.setItem("inventory_username", data.username);
        else localStorage.removeItem("inventory_username");
        window.location.href = "dashboard.html";
      } else {
        msg.className = "message error";
        msg.textContent = r.message || "Login failed.";
      }
    });
  });
})();
