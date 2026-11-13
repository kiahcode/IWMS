(function () {
  function request(url, data, callback) {
    var x = new XMLHttpRequest();
    x.open("POST", url, true);
    x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    x.onreadystatechange = function () {
      if (x.readyState === 4) {
        var r = { success: false, message: "Server error" };
        try {
          r = JSON.parse(x.responseText);
        } catch (e) {}
        callback(r);
      }
    };
    var b = [];
    for (var k in data)
      b.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k]));
    x.send(b.join("&"));
  }
  document
    .getElementById("registerForm")
    .addEventListener("submit", function (e) {
      e.preventDefault();
      var f = e.target,
        msg = document.getElementById("registerMessage"),
        p = f.password.value,
        c = f.confirm_password.value;
      if (p !== c) {
        msg.className = "message error";
        msg.textContent = "Passwords do not match.";
        return;
      }
      msg.textContent = "Creating account...";
      request(
        "php/register.php",
        {
          full_name: f.full_name.value,
          email: f.email.value,
          username: f.username.value,
          password: p,
          confirm_password: c,
        },
        function (r) {
          if (r.success) {
            msg.className = "message success";
            msg.textContent = "Account created successfully.";
            setTimeout(function () {
              location.href = "login.html";
            }, 900);
          } else {
            msg.className = "message error";
            msg.textContent = r.message || "Registration failed.";
          }
        },
      );
    });
})();
