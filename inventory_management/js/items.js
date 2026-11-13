var InventoryPage = {
  init: function () {
    var page = document.body.dataset.page;
    var endpoint =
      page === "raw-materials"
        ? "php/raw-materials.php"
        : page === "finished-goods"
          ? "php/finished-goods.php"
          : "php/items.php";
    var fixedType =
      page === "raw-materials"
        ? "Raw Material"
        : page === "finished-goods"
          ? "Finished Good"
          : "";
    var rows = [],
      editingId = null,
      modal = document.getElementById("itemModal"),
      form = document.getElementById("itemModalForm"),
      role = "USER";
    function applyRole(r) {
      role = r && r.user ? r.user.role : "USER";
      document.querySelectorAll(".admin-only").forEach(function (el) {
        el.style.display = role === "ADMIN" ? "" : "none";
      });
    }
    function loadCategories() {
      InventoryApp.request(
        "php/items.php",
        { action: "categories" },
        "GET",
        function (r) {
          if (!r.success) return;
          var c = document.getElementById("categorySelect");
          if (c)
            InventoryApp.fillSelect(
              c,
              r.categories,
              "id",
              "category_name",
              "Select category",
            );
          var f = document.getElementById("categoryFilter");
          if (f)
            InventoryApp.fillSelect(
              f,
              r.categories,
              "id",
              "category_name",
              "All Categories",
            );
        },
      );
    }
    function render() {
      var q = (document.getElementById("itemSearch")?.value || "")
          .toLowerCase()
          .trim(),
        selectedItem = document.getElementById("itemFilter")?.value || "",
        selectedSize = document.getElementById("sizeFilter")?.value || "",
        type = document.getElementById("typeFilter")?.value || fixedType,
        cat = document.getElementById("categoryFilter")?.value || "",
        tbody = document.getElementById("itemsTable");
      var data = rows.filter(function (x) {
        var rawSize = String(x.size || "")
            .trim()
            .replace(/[–—]/g, "-"),
          sizeNumber = parseInt(rawSize, 10),
          sizeMatch =
            !selectedSize ||
            (function () {
              var bounds = selectedSize.split("-").map(Number);
              return sizeNumber >= bounds[0] && sizeNumber <= bounds[1];
            })();
        return (
          (!selectedItem || String(x.item_name) === String(selectedItem)) &&
          sizeMatch &&
          (!type || x.item_type === type) &&
          (!cat || String(x.category_id) === String(cat)) &&
          (
            x.item_code +
            " " +
            x.item_name +
            " " +
            (x.brand || "") +
            " " +
            (x.supplier_name || "") +
            " " +
            (x.category_name || "")
          )
            .toLowerCase()
            .indexOf(q) >= 0
        );
      });
      tbody.innerHTML = data.length
        ? data
            .map(function (x) {
              var stock = Number(x.current_stock || 0),
                actions =
                  '<button class="action-btn" data-view="' +
                  x.id +
                  '">View</button>';
              if (role === "ADMIN")
                actions +=
                  '<button class="action-btn admin-only" data-edit="' +
                  x.id +
                  '">Edit</button>';
              if (fixedType === "Raw Material")
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.item_code) +
                  "</td><td>" +
                  InventoryApp.esc(x.item_name) +
                  "</td><td>" +
                  InventoryApp.esc(x.supplier_name || "") +
                  "</td><td>" +
                  InventoryApp.esc(x.category_name || "") +
                  "</td><td>" +
                  InventoryApp.esc(x.unit || "") +
                  "</td><td>" +
                  InventoryApp.qty(stock) +
                  "</td><td>" +
                  InventoryApp.qty(x.reorder_level) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name || "") +
                  '</td><td><span class="status ' +
                  (x.status === "Active" ? "active" : "critical") +
                  '">' +
                  InventoryApp.esc(x.status) +
                  "</span></td></tr>"
                );
              if (fixedType === "Finished Good")
                return (
                  "<tr><td>" +
                  InventoryApp.esc(x.item_code) +
                  "</td><td>" +
                  InventoryApp.esc(x.item_name) +
                  "</td><td>" +
                  InventoryApp.esc(x.brand || "") +
                  "</td><td>" +
                  InventoryApp.esc(x.size || "----") +
                  "</td><td>" +
                  InventoryApp.esc(x.unit || "") +
                  "</td><td>" +
                  InventoryApp.qty(stock) +
                  "</td><td>" +
                  InventoryApp.esc(x.warehouse_name || "") +
                  '</td><td><span class="status ' +
                  (x.status === "Active" ? "active" : "critical") +
                  '">' +
                  InventoryApp.esc(x.status) +
                  "</span></td></tr>"
                );
              return (
                "<tr><td>" +
                InventoryApp.esc(x.item_code) +
                "</td><td>" +
                InventoryApp.esc(x.item_name) +
                "</td><td>" +
                InventoryApp.esc(x.brand || "") +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material"
                    ? x.supplier_name || ""
                    : "----",
                ) +
                "</td><td>" +
                InventoryApp.esc(
                  x.item_type === "Raw Material" ? "----" : x.size || "----",
                ) +
                "</td><td>" +
                InventoryApp.esc(x.item_type || "") +
                "</td><td>" +
                InventoryApp.esc(x.category_name || "") +
                "</td><td>" +
                InventoryApp.esc(x.unit || "") +
                "</td><td>" +
                InventoryApp.qty(stock) +
                "</td><td>" +
                InventoryApp.qty(x.reorder_level) +
                '</td><td><span class="status ' +
                (x.status === "Active" ? "active" : "critical") +
                '">' +
                InventoryApp.esc(x.status) +
                '</span></td><td class="admin-action-col">' +
                actions +
                "</td></tr>"
              );
            })
            .join("")
        : '<tr><td colspan="' +
          (fixedType === "Raw Material"
            ? 9
            : fixedType === "Finished Good"
              ? 8
              : 12) +
          '" class="empty">No items found.</td></tr>';
    }
    function populateItemFilter() {
      var f = document.getElementById("itemFilter");
      if (!f) return;
      var names = Array.from(
        new Set(
          rows.map(function (x) {
            return x.item_name;
          }),
        ),
      );
      f.innerHTML =
        '<option value="">All Items</option>' +
        names
          .map(function (name) {
            return (
              '<option value="' +
              InventoryApp.esc(name) +
              '">' +
              InventoryApp.esc(name) +
              "</option>"
            );
          })
          .join("");
    }
    function load() {
      var d = { limit: 1000, type: fixedType },
        wf = document.getElementById("warehouseFilter");
      if (wf && wf.value) d.warehouse_id = wf.value;
      InventoryApp.request(endpoint, d, "GET", function (r) {
        if (r.success) {
          rows = r.items || [];
          populateItemFilter();
          render();
        }
      });
    }
    function syncItemSizeField() {
      var t = form && form.querySelector('[name="item_type"]'),
        s = form && form.querySelector('[name="size"]');
      if (!t || !s) return;
      if (t.value === "Raw Material") {
        s.value = "";
        s.disabled = true;
        s.placeholder = "---- (Raw Materials)";
        s.title = "Raw Materials do not have a size.";
      } else {
        s.disabled = false;
        s.placeholder = "Finished Goods size (e.g. 7, 8, 9)";
        s.title = "Enter the Finished Goods size.";
      }
    }
    if (document.getElementById("addItemBtn"))
      document.getElementById("addItemBtn").onclick = function () {
        if (role !== "ADMIN") return;
        editingId = null;
        form.reset();
        form.querySelector('[name="item_type"]').value =
          fixedType || "Raw Material";
        syncItemSizeField();
        document.querySelector("#itemModal .modal-head h3").textContent =
          "Add Item";
        InventoryApp.openModal("itemModal");
      };
    if (form && form.querySelector('[name="item_type"]'))
      form
        .querySelector('[name="item_type"]')
        .addEventListener("change", syncItemSizeField);
    if (document.getElementById("itemSearch"))
      document.getElementById("itemSearch").addEventListener("input", render);
    if (document.getElementById("itemFilter"))
      document.getElementById("itemFilter").addEventListener("change", render);
    if (document.getElementById("sizeFilter"))
      document.getElementById("sizeFilter").addEventListener("change", render);
    if (document.getElementById("typeFilter"))
      document.getElementById("typeFilter").addEventListener("change", render);
    if (document.getElementById("categoryFilter"))
      document
        .getElementById("categoryFilter")
        .addEventListener("change", render);
    if (document.getElementById("warehouseFilter")) {
      InventoryApp.loadWarehouses(function (ws) {
        var w = document.getElementById("warehouseFilter");
        w.innerHTML =
          '<option value="">All Warehouses</option>' +
          ws
            .map(function (x) {
              return (
                '<option value="' +
                x.id +
                '">' +
                InventoryApp.esc(x.warehouse_name) +
                "</option>"
              );
            })
            .join("");
        w.addEventListener("change", load);
      });
    }
    if (document.getElementById("itemsTable"))
      document
        .getElementById("itemsTable")
        .addEventListener("click", function (e) {
          var b = e.target,
            id = b.dataset.edit || b.dataset.view;
          if (!id) return;
          var x = rows.find(function (a) {
            return String(a.id) === String(id);
          });
          if (!x) return;
          if (b.dataset.view) {
            alert(
              "Item: " +
                x.item_code +
                "\nName: " +
                x.item_name +
                "\nBrand: " +
                (x.brand || "") +
                "\nSupplier: " +
                (x.item_type === "Raw Material"
                  ? x.supplier_name || ""
                  : "----") +
                "\nSize: " +
                (x.item_type === "Raw Material" ? "----" : x.size || "----") +
                "\nType: " +
                x.item_type +
                "\nCategory: " +
                (x.category_name || "") +
                "\nCurrent stock: " +
                x.current_stock,
            );
            return;
          }
          if (role !== "ADMIN") return;
          editingId = id;
          for (var k in x) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) el.value = x[k] ?? "";
          }
          syncItemSizeField();
          document.querySelector("#itemModal .modal-head h3").textContent =
            "Edit Item";
          InventoryApp.openModal("itemModal");
        });
    if (form)
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (role !== "ADMIN") return;
        var data = {
          action: editingId ? "update" : "create",
          id: editingId || "",
          item_code: form.item_code.value,
          item_name: form.item_name.value,
          brand: form.brand ? form.brand.value : "",
          supplier_name: form.supplier_name ? form.supplier_name.value : "",
          size: form.size ? form.size.value : "",
          item_type: form.item_type.value,
          category_id: form.category_id.value,
          unit: form.unit.value,
          description: form.description.value,
          reorder_level: form.reorder_level.value,
          status: form.status.value,
        };
        InventoryApp.request("php/items.php", data, "POST", function (r) {
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
    InventoryApp.session(function (r) {
      applyRole(r);
      loadCategories();
      load();
    });
  },
};
