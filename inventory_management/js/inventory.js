var InventoryApp = (function () {
  function encode(data) {
    var a = [];
    for (var k in data) {
      if (data[k] !== undefined && data[k] !== null)
        a.push(encodeURIComponent(k) + "=" + encodeURIComponent(data[k]));
    }
    return a.join("&");
  }
  function request(url, data, method, callback) {
    method = method || "GET";
    if (method === "GET") {
      var qs = encode(data || {});
      if (qs) url += (url.indexOf("?") >= 0 ? "&" : "?") + qs;
    }
    var x = new XMLHttpRequest();
    x.open(method, url, true);
    if (method === "POST")
      x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    x.onreadystatechange = function () {
      if (x.readyState === 4) {
        var r = { success: false, message: "Server error" };
        try {
          r = JSON.parse(x.responseText);
        } catch (e) {}
        callback(r, x.status);
      }
    };
    x.send(method === "POST" ? encode(data || {}) : null);
  }
  function toast(msg, ok) {
    var t = document.getElementById("toast");
    if (!t) return;
    t.textContent = msg;
    t.className = "toast show";
    setTimeout(function () {
      t.className = "toast";
    }, 2200);
  }
  function fillSelect(el, rows, valueField, textField, placeholder) {
    if (!el) return;
    el.innerHTML = placeholder
      ? '<option value="">' + placeholder + "</option>"
      : "";
    rows.forEach(function (r) {
      var o = document.createElement("option");
      o.value = r[valueField];
      o.textContent = r[textField];
      el.appendChild(o);
    });
  }
  function openModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.add("show");
  }
  function closeModal(m) {
    m.classList.remove("show");
  }
  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[c];
    });
  }
  function money(n) {
    return (
      "₱" +
      Number(n || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  }
  function qty(n) {
    return Number(n || 0).toLocaleString(undefined, {
      maximumFractionDigits: 2,
    });
  }
  function setupNotifications() {
    var actions = document.querySelector(".top-actions");
    if (!actions) return;
    var user = actions.querySelector(".user-chip");
    if (!user) {
      user = document.createElement("span");
      user.className = "user-chip";
      user.innerHTML = '<b id="userName">Loading...</b>';
    }
    actions.innerHTML = "";
    var wrap = document.createElement("div");
    wrap.className = "notification-wrap";
    var bell = document.createElement("button");
    bell.type = "button";
    bell.className = "notification-bell";
    bell.setAttribute("aria-label", "Notifications");
    bell.innerHTML =
      '<span class="bell-icon">🔔</span><span class="notification-badge" hidden>0</span>';
    var panel = document.createElement("div");
    panel.className = "notification-panel";
    panel.innerHTML =
      '<div class="notification-head"><strong>Notifications</strong><button type="button" class="notification-read-all">Mark all as read</button></div><div class="notification-list"><div class="notification-empty">No notifications yet.</div></div>';
    wrap.appendChild(bell);
    wrap.appendChild(panel);
    actions.appendChild(wrap);
    actions.appendChild(user);
    var badge = bell.querySelector(".notification-badge"),
      list = panel.querySelector(".notification-list"),
      readAll = panel.querySelector(".notification-read-all");
    function timeAgo(value) {
      var d = new Date(String(value).replace(" ", "T"));
      if (isNaN(d)) return value;
      var sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
      if (sec < 60) return "Just now";
      var min = Math.floor(sec / 60);
      if (min < 60) return min + " min ago";
      var hr = Math.floor(min / 60);
      if (hr < 24) return hr + " hr ago";
      return Math.floor(hr / 24) + " day ago";
    }
    function load() {
      request(
        "php/notifications.php",
        { action: "list", limit: 20 },
        "GET",
        function (r) {
          if (!r.success) return;
          var n = Number(r.unread || 0);
          badge.textContent = n >= 9 ? "9+" : String(n);
          badge.hidden = n <= 0;
          var rows = r.rows || [];
          list.innerHTML = rows.length
            ? rows
                .map(function (x) {
                  return (
                    '<button type="button" class="notification-item ' +
                    (Number(x.is_read) ? "read" : "unread") +
                    '" data-notification-id="' +
                    InventoryApp.esc(x.id) +
                    '"><span class="notification-dot"></span><span class="notification-copy"><b>' +
                    InventoryApp.esc(x.title) +
                    "</b><small>" +
                    InventoryApp.esc(x.message) +
                    "</small><em>" +
                    InventoryApp.esc(timeAgo(x.created_at)) +
                    "</em></span></button>"
                  );
                })
                .join("")
            : '<div class="notification-empty">No notifications yet.</div>';
        },
      );
    }
    bell.onclick = function (e) {
      e.stopPropagation();
      panel.classList.toggle("show");
      load();
    };
    panel.addEventListener("click", function (e) {
      var item = e.target.closest(".notification-item");
      if (!item) return;
      var id = item.dataset.notificationId;
      request(
        "php/notifications.php",
        { action: "read", id: id },
        "POST",
        function () {
          load();
        },
      );
    });
    readAll.onclick = function () {
      request(
        "php/notifications.php",
        { action: "read_all" },
        "POST",
        function () {
          load();
        },
      );
    };
    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) panel.classList.remove("show");
    });
    load();
    window.clearInterval(window.__notificationTimer);
    window.__notificationTimer = window.setInterval(load, 5000);
  }
  function session(callback) {
    request("php/check-session.php", {}, "GET", function (r) {
      if (!r.logged_in) {
        location.href = "login.html";
        return;
      }
      var user = r.user || {};
      var role = String(user.role || "USER")
        .trim()
        .toUpperCase();
      var canControl = !!user.can_control;
      var name = String(user.full_name || user.username || "Employee").trim();
      var position = String(user.position || "").trim();
      var u = document.getElementById("userName");
      if (u) {
        u.innerHTML =
          (position
            ? "<small>" + InventoryApp.esc(position) + "</small>"
            : "") +
          "<span>" +
          InventoryApp.esc(name) +
          "</span>";
        u.style.display = "inline-flex";
        u.setAttribute("data-role-label", position || role);
      }
      document.body.dataset.role = role;
      document.body.dataset.canControl = canControl ? "1" : "0";
      var a = document.getElementById("avatar");
      if (a) a.remove();
      document.querySelectorAll(".admin-only").forEach(function (el) {
        el.style.display = canControl ? "" : "none";
      });
      document
        .querySelectorAll('[data-page="inventory-reports"]')
        .forEach(function (el) {
          el.style.display = canControl ? "" : "none";
        });
      setupNotifications();
      r.user.role = role;
      r.user.can_control = canControl;
      callback(r);
    });
  }
  function loadItems(callback) {
    request("php/items.php", { limit: 1000 }, "GET", function (r) {
      callback(r.success ? r.items : []);
    });
  }
  function loadWarehouses(callback) {
    request("php/warehouses.php", { limit: 1000 }, "GET", function (r) {
      callback(r.success ? r.warehouses : []);
    });
  }
  function modalSetup() {
    document.querySelectorAll(".modal").forEach(function (m) {
      m.querySelectorAll(".modal-close").forEach(function (b) {
        b.addEventListener("click", function () {
          closeModal(m);
        });
      });
      m.addEventListener("click", function (e) {
        if (e.target === m) closeModal(m);
      });
    });
  }
  function setupNav() {
    var page = document.body.dataset.page;
    document.querySelectorAll("[data-page]").forEach(function (a) {
      if (a.dataset.page === page) a.classList.add("active");
    });
    var sub = document.getElementById("inventorySubmenu");
    if (sub && page !== "dashboard") {
      sub.classList.add("open");
    }
    var toggle = document.querySelector(".inv-toggle");
    if (toggle)
      toggle.onclick = function () {
        if (sub) sub.classList.toggle("open");
      };
    var mob = document.getElementById("mobileMenu"),
      side = document.getElementById("sidebar");
    if (mob)
      mob.onclick = function () {
        side.classList.toggle("open");
      };
    var logout = document.getElementById("logoutBtn");
    if (logout)
      logout.onclick = function (e) {
        e.preventDefault();
        request("php/logout.php", {}, "POST", function () {
          location.href = "login.html";
        });
      };
  }
  function commonSearch() {
    var g = document.getElementById("globalSearch");
    if (g)
      g.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          var q = g.value.trim();
          if (q) location.href = "item-master.html?q=" + encodeURIComponent(q);
        }
      });
  }

  window.InventoryPage = {
    init: function () {
      var page = document.body.dataset.page;
      function today() {
        var d = new Date();
        return d.toISOString().slice(0, 10);
      }
      function populateForms() {
        InventoryApp.loadItems(function (items) {
          document
            .querySelectorAll('select[name="item_id"]')
            .forEach(function (s) {
              s.innerHTML =
                '<option value="">Select item</option>' +
                items
                  .map(function (i) {
                    return (
                      '<option value="' +
                      i.id +
                      '" data-type="' +
                      InventoryApp.esc(i.item_type) +
                      '" data-unit="' +
                      InventoryApp.esc(i.unit || "") +
                      '">' +
                      InventoryApp.esc(
                        i.item_code +
                          " - " +
                          i.item_name +
                          " (Size: " +
                          (i.item_type === "Raw Material"
                            ? "----"
                            : i.size || "----") +
                          ")",
                      ) +
                      "</option>"
                    );
                  })
                  .join("");
            });
        });
        InventoryApp.loadWarehouses(function (ws) {
          document
            .querySelectorAll('select[name="warehouse_id"],#fromWarehouse')
            .forEach(function (s) {
              s.innerHTML =
                '<option value="">Select warehouse</option>' +
                ws
                  .map(function (w) {
                    return (
                      '<option value="' +
                      w.id +
                      '">' +
                      InventoryApp.esc(w.warehouse_name) +
                      "</option>"
                    );
                  })
                  .join("");
            });
          var to = document.getElementById("toWarehouse");
          if (to)
            to.innerHTML =
              '<option value="">Select warehouse</option>' +
              ws
                .map(function (w) {
                  return (
                    '<option value="' +
                    w.id +
                    '">' +
                    InventoryApp.esc(w.warehouse_name) +
                    "</option>"
                  );
                })
                .join("");
        });
      }
      function transaction(api, modalId, fields, headers, rowFn) {
        var rows = [],
          editing = null,
          modal = document.getElementById(modalId),
          form = document.getElementById(modalId + "Form"),
          selectedDestination = "";
        function load() {
          InventoryApp.request(
            "php/" + api,
            { action: "list" },
            "GET",
            function (r) {
              if (r.success) {
                rows = r.rows || [];
                populateFilter();
                render();
              }
            },
          );
        }
        function populateFilter() {
          var sel = document.getElementById("itemFilter");
          if (sel) {
            var seen = {};
            sel.innerHTML = '<option value="">All Items</option>';
            rows.forEach(function (x) {
              if (x.item_id && !seen[x.item_id]) {
                seen[x.item_id] = 1;
                var o = document.createElement("option");
                o.value = x.item_id;
                o.textContent =
                  (x.item_code || "") + " - " + (x.item_name || "");
                sel.appendChild(o);
              }
            });
          }
        }
        function render() {
          var q = (
              document.getElementById("tableSearch")?.value || ""
            ).toLowerCase(),
            df = document.getElementById("dateFrom")?.value || "",
            dt = document.getElementById("dateTo")?.value || "",
            item = document.getElementById("itemFilter")?.value || "",
            type = document.getElementById("typeFilter")?.value || "";
          var tb = document.getElementById("transactionTable");
          var data = rows.filter(function (x) {
            return (
              JSON.stringify(x).toLowerCase().indexOf(q) >= 0 &&
              (!df || x.date >= df) &&
              (!dt || x.date <= dt) &&
              (!item || String(x.item_id) === String(item)) &&
              (!type || x.item_type === type) &&
              (!selectedDestination ||
                String(x.destination || "") === selectedDestination)
            );
          });
          tb.innerHTML = data.length
            ? data
                .map(function (x) {
                  return rowFn(x);
                })
                .join("")
            : '<tr><td colspan="20" class="empty">No records found.</td></tr>';
        }
        document.getElementById("addTransactionBtn").onclick = function () {
          editing = null;
          form.reset();
          form.querySelector('[name="date"]').value = today();
          populateForms();
          InventoryApp.openModal(modalId);
        };
        document.getElementById("tableSearch").oninput = render;
        document.getElementById("filterBtn").onclick = render;
        document.querySelectorAll(".destination-tab").forEach(function (tab) {
          tab.addEventListener("click", function () {
            selectedDestination = this.dataset.destination || "";
            document.querySelectorAll(".destination-tab").forEach(function (t) {
              t.classList.toggle("active", t === tab);
            });
            render();
          });
        });
        ["itemFilter", "typeFilter"].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.onchange = render;
        });
        document.getElementById("transactionTable").onclick = function (e) {
          var b = e.target,
            id = b.dataset.edit || b.dataset.delete;
          if (!id) return;
          if (b.dataset.delete) {
            if (confirm("Delete this transaction?"))
              InventoryApp.request(
                "php/" + api,
                { action: "delete", id: id },
                "POST",
                function (r) {
                  InventoryApp.toast(r.message);
                  if (r.success) load();
                },
              );
            return;
          }
          var x = rows.find(function (a) {
            return a.id == id;
          });
          editing = id;
          for (var k in x) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) el.value = x[k] ?? "";
          }
          if (form.querySelector('[name="date"]'))
            form.querySelector('[name="date"]').value = x.date;
          populateForms();
          setTimeout(function () {
            for (var k in x) {
              var el = form.querySelector('[name="' + k + '"]');
              if (el) el.value = x[k] ?? "";
            }
          }, 100);
          InventoryApp.openModal(modalId);
        };
        form.addEventListener("submit", function (e) {
          e.preventDefault();
          var data = {
            action: editing ? "update" : "create",
            id: editing || "",
          };
          fields.forEach(function (k) {
            var el = form.querySelector('[name="' + k + '"]');
            data[k] = el ? el.value : "";
          });
          InventoryApp.request("php/" + api, data, "POST", function (r) {
            var m = form.querySelector(".form-message");
            m.className = "form-message " + (r.success ? "success" : "error");
            m.textContent = r.message;
            if (r.success) {
              InventoryApp.toast(r.message);
              setTimeout(function () {
                InventoryApp.closeModal(modal);
                load();
              }, 300);
            }
          });
        });
        load();
      }
      if (
        ["stock-in", "stock-out", "stock-transfer", "stock-adjustment"].indexOf(
          page,
        ) >= 0
      ) {
        if (page === "stock-in")
          transaction(
            "stock-in.php",
            "stockInModal",
            [
              "stock_in_number",
              "date",
              "item_id",
              "warehouse_id",
              "quantity",
              "unit",
              "supplier",
              "reference_number",
              "remarks",
            ],
            null,
            function (x) {
              return (
                "<tr><td>" +
                InventoryApp.esc(x.stock_in_number) +
                "</td><td>" +
                x.date +
                "</td><td>" +
                InventoryApp.esc(x.item_code + " - " + x.item_name) +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material" ? "----" : x.size || "----",
                ) +
                "</td><td>" +
                InventoryApp.esc(x.warehouse_name) +
                "</td><td>" +
                InventoryApp.qty(x.quantity) +
                "</td><td>" +
                InventoryApp.esc(x.unit || "") +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material" ? x.supplier : "",
                ) +
                "</td><td>" +
                InventoryApp.esc(x.reference_number) +
                '</td><td class="admin-action-col"><button class="action-btn admin-only" data-edit="' +
                x.id +
                '">Edit</button></td></tr>'
              );
            },
          );
        if (page === "stock-out")
          transaction(
            "stock-out.php",
            "stockOutModal",
            [
              "stock_out_number",
              "date",
              "item_id",
              "warehouse_id",
              "quantity",
              "purpose",
              "destination",
              "reference_number",
              "remarks",
            ],
            null,
            function (x) {
              return (
                "<tr><td>" +
                InventoryApp.esc(x.stock_out_number) +
                "</td><td>" +
                x.date +
                "</td><td>" +
                InventoryApp.esc(x.item_code + " - " + x.item_name) +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material" ? "----" : x.size || "----",
                ) +
                "</td><td>" +
                InventoryApp.esc(x.warehouse_name) +
                "</td><td>" +
                InventoryApp.qty(x.quantity) +
                "</td><td>" +
                InventoryApp.esc(x.purpose) +
                "</td><td>" +
                InventoryApp.esc(x.destination || "") +
                "</td><td>" +
                InventoryApp.esc(x.reference_number) +
                '</td><td class="admin-action-col"><button class="action-btn admin-only" data-edit="' +
                x.id +
                '">Edit</button></td></tr>'
              );
            },
          );

        if (page === "stock-adjustment")
          transaction(
            "stock-adjustment.php",
            "adjustmentModal",
            [
              "adjustment_number",
              "date",
              "item_id",
              "warehouse_id",
              "adjusted_quantity",
              "reason",
              "remarks",
            ],
            null,
            function (x) {
              return (
                "<tr><td>" +
                InventoryApp.esc(x.adjustment_number) +
                "</td><td>" +
                x.date +
                "</td><td>" +
                InventoryApp.esc(x.item_code + " - " + x.item_name) +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material" ? "----" : x.size || "----",
                ) +
                "</td><td>" +
                InventoryApp.esc(x.warehouse_name) +
                "</td><td>" +
                InventoryApp.qty(x.beginning_stock) +
                "</td><td>" +
                InventoryApp.qty(x.stock_in_quantity) +
                "</td><td>" +
                InventoryApp.qty(x.stock_out_quantity) +
                "</td><td>" +
                InventoryApp.qty(x.total_balance) +
                "</td><td>" +
                InventoryApp.qty(x.adjusted_quantity) +
                "</td><td>" +
                InventoryApp.qty(x.difference) +
                "</td><td>" +
                InventoryApp.esc(x.reason) +
                "</td></tr>"
              );
            },
          );
        if (page === "stock-in") {
          var siForm = document.getElementById("stockInModalForm"),
            siItem = siForm.querySelector('[name="item_id"]'),
            siSupField = document.getElementById("supplierField"),
            siSup = siForm.querySelector('[name="supplier"]'),
            siUnit = siForm.querySelector('[name="unit"]');
          function syncSupplier(autoUnit) {
            var o = siItem.options[siItem.selectedIndex],
              isRaw = !!(o && o.dataset.type === "Raw Material");
            siSupField.style.display = isRaw ? "" : "none";
            siSup.required = isRaw;
            if (!isRaw) siSup.value = "";
            if (autoUnit && o && o.dataset.unit) siUnit.value = o.dataset.unit;
          }
          siItem.addEventListener("change", function () {
            syncSupplier(true);
          });
          document
            .getElementById("addTransactionBtn")
            .addEventListener("click", function () {
              setTimeout(function () {
                syncSupplier(false);
              }, 0);
            });
          document
            .getElementById("transactionTable")
            .addEventListener("click", function (e) {
              if (e.target.dataset.edit)
                setTimeout(function () {
                  syncSupplier(false);
                }, 250);
            });
        }
        if (page !== "stock-transfer") populateForms();
        var adj = document.getElementById("adjustedQuantity");
        if (adj)
          adj.addEventListener("input", function () {
            var c = parseFloat(
                document.getElementById("currentQuantity").value || 0,
              ),
              a = parseFloat(adj.value || 0);
            document.getElementById("difference").value = (a - c).toFixed(2);
          });
      }
      if (page === "warehouse") {
        var rows = [],
          editing = null,
          form = document.getElementById("warehouseModalForm"),
          selectedWarehouse = 1,
          warehouseItems = [];
        function load() {
          InventoryApp.request("php/warehouses.php", {}, "GET", function (r) {
            if (r.success) {
              rows = (r.warehouses || []).sort(function (a, b) {
                return (Number(b.id) || 0) - (Number(a.id) || 0);
              });
              render();
              loadSummary(selectedWarehouse);
            }
          });
        }
        function render() {
          var q = (
            document.getElementById("warehouseSearch").value || ""
          ).toLowerCase();
          document.getElementById("warehouseTable").innerHTML =
            rows
              .filter(function (x) {
                return JSON.stringify(x).toLowerCase().indexOf(q) >= 0;
              })
              .map(function (x) {
                var actions =
                  '<button class="action-btn" data-view="' +
                  x.id +
                  '">View</button>';
                if (
                  document.querySelector(".admin-only")?.style.display !==
                  "none"
                )
                  actions +=
                    '<button class="action-btn admin-only" data-edit="' +
                    x.id +
                    '">Edit</button>';
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.warehouse_code) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name) +
                  "</td><td>" +
                  InventoryApp.esc(x.location) +
                  "</td><td>" +
                  InventoryApp.esc(x.manager) +
                  "</td><td>" +
                  InventoryApp.qty(x.total_stock) +
                  '</td><td class="admin-action-col">' +
                  actions +
                  "</td></tr>"
                );
              })
              .join("") ||
            '<tr><td colspan="6" class="empty">No warehouses found.</td></tr>';
        }
        function renderWarehouseItems() {
          var item =
              document.getElementById("warehouseItemFilter")?.value || "",
            type = document.getElementById("warehouseTypeFilter")?.value || "",
            data = warehouseItems.filter(function (x) {
              return (
                (!item || String(x.id) === String(item)) &&
                (!type || x.item_type === type)
              );
            });
          var tb = document.getElementById("warehouseItemsTable");
          if (!tb) return;
          tb.innerHTML = data.length
            ? data
                .map(function (x) {
                  return (
                    "<tr><td>" +
                    InventoryApp.esc(x.item_code) +
                    "</td><td>" +
                    InventoryApp.esc(x.item_name) +
                    "</td><td>" +
                    InventoryApp.esc(x.item_type) +
                    "</td><td>" +
                    InventoryApp.esc(
                      x.item_type === "Raw Material"
                        ? "----"
                        : x.size || "----",
                    ) +
                    "</td><td>" +
                    InventoryApp.esc(x.brand || "") +
                    "</td><td>" +
                    InventoryApp.esc(x.category_name || "") +
                    "</td><td>" +
                    InventoryApp.esc(x.unit || "") +
                    "</td><td>" +
                    InventoryApp.qty(x.current_stock) +
                    "</td></tr>"
                  );
                })
                .join("")
            : '<tr><td colspan="8" class="empty">No items in this warehouse.</td></tr>';
        }

        function loadSummary(id) {
          selectedWarehouse = id;
          InventoryApp.request(
            "php/warehouses.php",
            { action: "summary", warehouse_id: id },
            "GET",
            function (r) {
              if (!r.success) return;
              var s = r.summary || {};
              warehouseItems = r.items || [];
              document.getElementById("warehouseItems").textContent =
                InventoryApp.qty(s.item_count);
              document.getElementById("warehouseStock").textContent =
                InventoryApp.qty(s.current_stock);
              document.getElementById("warehouseStockIn").textContent =
                InventoryApp.qty(s.total_stock_in);
              document.getElementById("warehouseStockOut").textContent =
                InventoryApp.qty(s.total_stock_out);
              document.querySelectorAll(".warehouse-tab").forEach(function (b) {
                b.classList.toggle(
                  "active",
                  String(b.dataset.id) === String(id),
                );
              });
              renderWarehouseItems();
            },
          );
        }
        document.getElementById("addWarehouseBtn").onclick = function () {
          editing = null;
          form.reset();
          InventoryApp.openModal("warehouseModal");
        };
        document.getElementById("warehouseSearch").oninput = render;
        ["warehouseTypeFilter"].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.onchange = renderWarehouseItems;
        });
        document.querySelectorAll(".warehouse-tab").forEach(function (b) {
          b.onclick = function () {
            loadSummary(b.dataset.id);
          };
        });
        document.getElementById("warehouseTable").onclick = function (e) {
          var b = e.target,
            id = b.dataset.edit || b.dataset.view;
          if (!id) return;
          if (b.dataset.view) {
            loadSummary(id);
            return;
          }
          editing = id;
          var x = rows.find(function (a) {
            return a.id == id;
          });
          for (var k in x) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) el.value = x[k] ?? "";
          }
          InventoryApp.openModal("warehouseModal");
        };
        form.addEventListener("submit", function (e) {
          e.preventDefault();
          var d = {
            action: editing ? "update" : "create",
            id: editing || "",
            warehouse_code: form.warehouse_code.value,
            warehouse_name: form.warehouse_name.value,
            location: form.location.value,
            manager: form.manager.value,
            status: form.status.value,
          };
          InventoryApp.request("php/warehouses.php", d, "POST", function (r) {
            var m = form.querySelector(".form-message");
            m.className = "form-message " + (r.success ? "success" : "error");
            m.textContent = r.message;
            if (r.success) {
              InventoryApp.closeModal(
                document.getElementById("warehouseModal"),
              );
              load();
            }
          });
        });
        load();
      }
      if (page === "stock-card") {
        var itemSel = document.getElementById("cardItem"),
          whSel = document.getElementById("cardWarehouse"),
          allItems = [];
        InventoryApp.loadItems(function (x) {
          allItems = x;
          itemSel.innerHTML =
            '<option value="">All Items</option>' +
            x
              .map(function (i) {
                return (
                  '<option value="' +
                  i.id +
                  '">' +
                  InventoryApp.esc(i.item_code + " - " + i.item_name) +
                  "</option>"
                );
              })
              .join("");
        });
        InventoryApp.loadWarehouses(function (x) {
          whSel.innerHTML =
            '<option value="">All Warehouses</option>' +
            x
              .map(function (w) {
                return (
                  '<option value="' +
                  w.id +
                  '">' +
                  InventoryApp.esc(w.warehouse_name) +
                  "</option>"
                );
              })
              .join("");
        });
        document.getElementById("cardTypeFilter").onchange = function () {
          var t = this.value;
          itemSel.innerHTML =
            '<option value="">All Items</option>' +
            allItems
              .filter(function (i) {
                return !t || i.item_type === t;
              })
              .map(function (i) {
                return (
                  '<option value="' +
                  i.id +
                  '">' +
                  InventoryApp.esc(i.item_code + " - " + i.item_name) +
                  "</option>"
                );
              })
              .join("");
        };
        document.getElementById("cardFilter").onclick = function () {
          InventoryApp.request(
            "php/stock-card.php",
            {
              item_id: itemSel.value,
              warehouse_id: whSel.value,
              date_from: document.getElementById("cardFrom").value,
              date_to: document.getElementById("cardTo").value,
            },
            "GET",
            function (r) {
              var data = r.rows || [];
              document.getElementById("stockCardTable").innerHTML =
                data
                  .map(function (x) {
                    return (
                      "<tr><td>" +
                      InventoryApp.esc(x.date) +
                      "</td><td>" +
                      InventoryApp.esc(x.item_code + " - " + x.item_name) +
                      "</td><td>" +
                      InventoryApp.esc(
                        x.item_type === "Raw Material"
                          ? "----"
                          : x.size || "----",
                      ) +
                      "</td><td>" +
                      InventoryApp.esc(x.warehouse_name) +
                      "</td><td>" +
                      InventoryApp.esc(x.reference_number || "") +
                      "</td><td>" +
                      InventoryApp.esc(x.movement_type) +
                      "</td><td>" +
                      InventoryApp.qty(x.stock_in) +
                      "</td><td>" +
                      InventoryApp.qty(x.stock_out) +
                      "</td><td><b>" +
                      InventoryApp.qty(x.balance) +
                      "</b></td><td>" +
                      InventoryApp.esc(x.username || "") +
                      "</td></tr>"
                    );
                  })
                  .join("") ||
                '<tr><td colspan="10" class="empty">No stock card records.</td></tr>';
            },
          );
        };
        document.getElementById("cardFilter").click();
      }
      if (page === "stock-movement") {
        var movementItem = document.getElementById("movementItem"),
          movementWarehouse = document.getElementById("movementWarehouse"),
          movementRows = [];
        var fill = function () {
          InventoryApp.loadItems(function (x) {
            movementItem.innerHTML =
              '<option value="">All Items</option>' +
              x
                .map(function (i) {
                  return (
                    '<option value="' +
                    i.id +
                    '">' +
                    InventoryApp.esc(i.item_code + " - " + i.item_name) +
                    "</option>"
                  );
                })
                .join("");
          });
          InventoryApp.loadWarehouses(function (x) {
            movementWarehouse.innerHTML =
              '<option value="">All Warehouses</option>' +
              x
                .map(function (w) {
                  return (
                    '<option value="' +
                    w.id +
                    '">' +
                    InventoryApp.esc(w.warehouse_name) +
                    "</option>"
                  );
                })
                .join("");
          });
        };
        fill();
        function loadmv() {
          InventoryApp.request(
            "php/stock-movement.php",
            {
              item_id: movementItem.value,
              warehouse_id: movementWarehouse.value,
              movement_type: document.getElementById("movementType").value,
              date_from: document.getElementById("movementFrom").value,
              date_to: document.getElementById("movementTo").value,
            },
            "GET",
            function (r) {
              movementRows = r.success ? r.rows || [] : [];
              renderMovement();
            },
          );
        }
        function renderMovement() {
          var t = document.getElementById("movementTypeFilter").value,
            item = movementItem.value;
          var data = movementRows.filter(function (x) {
            return (
              (!t || x.item_type === t) &&
              (!item || String(x.item_id) === String(item))
            );
          });
          document.getElementById("movementTable").innerHTML =
            data
              .map(function (x) {
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.date) +
                  "</td><td>" +
                  InventoryApp.esc(x.item_code + " - " + x.item_name) +
                  "</td><td>" +
                  InventoryApp.esc(
                    x.item_type === "Raw Material" ? "----" : x.size || "----",
                  ) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name) +
                  "</td><td>" +
                  InventoryApp.esc(x.movement_type) +
                  "</td><td>" +
                  InventoryApp.qty(x.quantity) +
                  "</td><td>" +
                  InventoryApp.esc(x.reference_number || "") +
                  "</td><td>" +
                  InventoryApp.esc(x.username || "") +
                  "</td><td>" +
                  InventoryApp.esc(x.remarks || "") +
                  "</td></tr>"
                );
              })
              .join("") ||
            '<tr><td colspan="9" class="empty">No movements.</td></tr>';
        }
        document.getElementById("movementFilter").onclick = loadmv;
        document.getElementById("movementTypeFilter").onchange = renderMovement;
        movementItem.onchange = renderMovement;
        movementWarehouse.onchange = loadmv;
        loadmv();
      }
      if (page === "reorder-level") {
        var reorderRows = [];
        function populateReorderFilter() {
          var sel = document.getElementById("itemFilter");
          if (!sel) return;
          var seen = {};
          sel.innerHTML = '<option value="">All Items</option>';
          reorderRows.forEach(function (x) {
            if (!seen[x.id]) {
              seen[x.id] = 1;
              var o = document.createElement("option");
              o.value = x.id;
              o.textContent = x.item_code + " - " + x.item_name;
              sel.appendChild(o);
            }
          });
        }
        function renderReorder() {
          var item = document.getElementById("itemFilter").value || "",
            type = document.getElementById("typeFilter").value || "",
            data = reorderRows.filter(function (x) {
              return (
                (!item || String(x.id) === String(item)) &&
                (!type || x.item_type === type)
              );
            });
          document.getElementById("reorderTable").innerHTML =
            data
              .map(function (x) {
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.item_code + " - " + x.item_name) +
                  "</td><td>" +
                  InventoryApp.esc(
                    x.item_type === "Raw Material" ? "----" : x.size || "----",
                  ) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name) +
                  "</td><td>" +
                  InventoryApp.qty(x.quantity) +
                  '</td><td><input class="reorder-input" data-id="' +
                  x.id +
                  '" value="' +
                  x.reorder_level +
                  '" type="number" min="0" step="0.01"></td><td><span class="status ' +
                  (x.status === "STOCK OK" ? "ok" : "low") +
                  '">' +
                  InventoryApp.esc(x.status) +
                  '</span></td><td><button class="action-btn admin-only save-reorder" data-id="' +
                  x.id +
                  '">Save</button></td></tr>'
                );
              })
              .join("") ||
            '<tr><td colspan="7" class="empty">No inventory.</td></tr>';
          document.querySelectorAll(".admin-only").forEach(function (el) {
            el.style.display =
              document.body.dataset.role === "ADMIN" ? "" : "none";
          });
        }
        function load() {
          InventoryApp.request(
            "php/reorder-level.php",
            {},
            "GET",
            function (r) {
              reorderRows = r.success ? r.rows || [] : [];
              populateReorderFilter();
              renderReorder();
            },
          );
        }
        document.getElementById("reorderTable").onclick = function (e) {
          if (e.target.classList.contains("save-reorder")) {
            if (document.body.dataset.role !== "ADMIN") return;
            var id = e.target.dataset.id,
              input = document.querySelector(
                '.reorder-input[data-id="' + id + '"]',
              );
            if (!input) return;
            InventoryApp.request(
              "php/reorder-level.php",
              { action: "update", id: id, reorder_level: input.value },
              "POST",
              function (r) {
                InventoryApp.toast(r.message);
                if (r.success) load();
              },
            );
          }
        };
        document.getElementById("itemFilter").onchange = renderReorder;
        document.getElementById("typeFilter").onchange = renderReorder;
        load();
      }
      if (page === "low-stock-alert") {
        var lowRows = [];
        function renderLow() {
          var item = document.getElementById("itemFilter").value || "",
            type = document.getElementById("typeFilter").value || "";
          var data = lowRows.filter(function (x) {
            return (
              (!item || String(x.id) === String(item)) &&
              (!type || x.item_type === type)
            );
          });
          document.getElementById("lowCount").textContent =
            data.length + " alerts";
          // Keep the five table cells in exactly the same order as the five headers.
          document.getElementById("lowStockTable").innerHTML =
            data
              .map(function (x) {
                var balance =
                  x.remaining_balance !== undefined &&
                  x.remaining_balance !== null
                    ? x.remaining_balance
                    : x.quantity;
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.item_name || "—") +
                  "</td><td>" +
                  InventoryApp.esc(
                    x.item_type === "Raw Material" ? "—" : x.size || "—",
                  ) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name || "—") +
                  "</td><td>" +
                  InventoryApp.qty(balance) +
                  '</td><td><span class="status ' +
                  (x.status === "Critical" ? "critical" : "low") +
                  '">' +
                  InventoryApp.esc(x.status || "Low Stock") +
                  "</span></td></tr>"
                );
              })
              .join("") ||
            '<tr><td colspan="5" class="empty">No low stock alerts.</td></tr>';
        }
        function load() {
          InventoryApp.request(
            "php/low-stock-alert.php",
            {},
            "GET",
            function (r) {
              lowRows = r.success ? r.rows || [] : [];
              var sel = document.getElementById("itemFilter");
              sel.innerHTML = '<option value="">All Items</option>';
              var seen = {};
              lowRows.forEach(function (x) {
                if (!seen[x.id]) {
                  seen[x.id] = 1;
                  sel.insertAdjacentHTML(
                    "beforeend",
                    '<option value="' +
                      InventoryApp.esc(x.id) +
                      '">' +
                      InventoryApp.esc(x.item_code + " - " + x.item_name) +
                      "</option>",
                  );
                }
              });
              renderLow();
            },
          );
        }
        document.getElementById("itemFilter").onchange = renderLow;
        document.getElementById("typeFilter").onchange = renderLow;
        load();
      }
    },
  };

  document.addEventListener("DOMContentLoaded", function () {
    if (document.body.classList.contains("app")) {
      setupNav();
      modalSetup();
      commonSearch();
      session(function () {
        if (window.InventoryPage && InventoryPage.init) InventoryPage.init();
      });
    }
  });
  return {
    request: request,
    toast: toast,
    fillSelect: fillSelect,
    openModal: openModal,
    closeModal: closeModal,
    esc: esc,
    money: money,
    qty: qty,
    session: session,
    loadItems: loadItems,
    loadWarehouses: loadWarehouses,
  };
})();
