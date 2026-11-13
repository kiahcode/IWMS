(function () {
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
  function req(url, data, method, cb) {
    InventoryApp.request(url, data, method, cb);
  }
  function today() {
    return new Date().toISOString().slice(0, 10);
  }

  function init() {
    var modal = document.getElementById("transferModal"),
      form = document.getElementById("transferModalForm"),
      add = document.getElementById("addTransactionBtn");
    var list = document.getElementById("transferItemsList"),
      typeFilter = document.getElementById("typeFilter"),
      itemFilter = document.getElementById("itemFilter");
    if (!modal || !form || !add || !list) return;
    var rows = [],
      items = [],
      warehouses = [],
      editing = null,
      ready = false;

    function loadDependencies(done) {
      var pending = 2;
      function finish() {
        pending--;
        if (pending <= 0) {
          ready = true;
          renderWarehouseSelects();
          populateItemFilter();
          if (done) done();
        }
      }
      req("php/items.php", { limit: 1000 }, "GET", function (r) {
        items = r && r.success ? r.items || [] : [];
        finish();
      });
      req("php/warehouses.php", { limit: 1000 }, "GET", function (r) {
        warehouses = r && r.success ? r.warehouses || [] : [];
        finish();
      });
    }
    function loadRows() {
      req("php/stock-transfer.php", { action: "list" }, "GET", function (r) {
        if (r && r.success) {
          rows = r.rows || [];
          render();
        } else {
          rows = [];
          render();
        }
      });
    }
    function whOptions(selected) {
      return (
        '<option value="">Select warehouse</option>' +
        warehouses
          .map(function (w) {
            return (
              '<option value="' +
              esc(w.id) +
              '" ' +
              (String(selected) === String(w.id) ? "selected" : "") +
              ">" +
              esc(w.warehouse_code || "WH-" + w.id) +
              " — " +
              esc(w.warehouse_name || "") +
              " (ID: " +
              esc(w.id) +
              ")</option>"
            );
          })
          .join("")
      );
    }
    function itemOptions(type, selected) {
      var data = items.filter(function (i) {
        return !type || i.item_type === type;
      });
      return (
        '<option value="">Select item</option>' +
        data
          .map(function (i) {
            var size = i.item_type === "Finished Good" ? i.size || "" : "----";
            return (
              '<option value="' +
              esc(i.id) +
              '" ' +
              (String(selected) === String(i.id) ? "selected" : "") +
              ">" +
              esc(
                i.item_code +
                  " — " +
                  i.item_name +
                  (size ? " — Size " + size : ""),
              ) +
              "</option>"
            );
          })
          .join("")
      );
    }
    function rowHtml(r) {
      return (
        '<div class="transfer-item-row" data-row="' +
        esc(r.key || "") +
        '">' +
        '<div><label>Type<select class="transfer-type"><option value="">Select type</option><option value="Raw Material" ' +
        (r.type === "Raw Material" ? "selected" : "") +
        '>Raw Materials</option><option value="Finished Good" ' +
        (r.type === "Finished Good" ? "selected" : "") +
        ">Finished Goods</option></select></label></div>" +
        '<div><label>Item<select class="transfer-item" ' +
        (!r.type ? "disabled" : "") +
        ">" +
        itemOptions(r.type, r.item_id) +
        "</select></label></div>" +
        '<div><label>Quantity<input class="transfer-qty" type="number" min="0.01" step="0.01" value="' +
        esc(r.quantity || "") +
        '"></label><small class="available-stock"></small></div>' +
        '<button type="button" class="remove-transfer-item" title="Remove item">×</button></div>'
      );
    }
    function addItemRow(data) {
      data = data || {};
      data.key = Date.now() + Math.random();
      list.insertAdjacentHTML("beforeend", rowHtml(data));
      updateAvailability(list.lastElementChild);
    }
    function renderWarehouseSelects(from, to) {
      var f = document.getElementById("fromWarehouse"),
        t = document.getElementById("toWarehouse");
      if (f) f.innerHTML = whOptions(from || "");
      if (t) t.innerHTML = whOptions(to || "");
    }
    function updateAvailability(row) {
      if (!row) return;
      var itemId = row.querySelector(".transfer-item")?.value,
        from = document.getElementById("fromWarehouse")?.value,
        small = row.querySelector(".available-stock");
      if (!itemId || !from) {
        if (small) small.textContent = "";
        return;
      }
      req(
        "php/stock-transfer.php",
        { action: "availability", item_id: itemId, warehouse_id: from },
        "GET",
        function (r) {
          if (small)
            small.textContent =
              r && r.success
                ? "Available: " + InventoryApp.qty(r.quantity)
                : "Availability unavailable";
        },
      );
    }
    function populateItemFilter() {
      if (!itemFilter) return;
      itemFilter.innerHTML =
        '<option value="">All Items</option>' +
        items
          .map(function (i) {
            return (
              '<option value="' +
              esc(i.id) +
              '">' +
              esc(i.item_code + " — " + i.item_name) +
              "</option>"
            );
          })
          .join("");
    }
    function render() {
      var q = (
          document.getElementById("tableSearch")?.value || ""
        ).toLowerCase(),
        type = typeFilter?.value || "",
        item = itemFilter?.value || "",
        df = document.getElementById("dateFrom")?.value || "",
        dt = document.getElementById("dateTo")?.value || "";
      var data = rows.filter(function (x) {
        return (
          JSON.stringify(x).toLowerCase().indexOf(q) >= 0 &&
          (!type ||
            (x.items || []).some(function (i) {
              return i.item_type === type;
            })) &&
          (!item ||
            (x.items || []).some(function (i) {
              return String(i.item_id) === String(item);
            })) &&
          (!df || x.date >= df) &&
          (!dt || x.date <= dt)
        );
      });
      var tb = document.getElementById("transactionTable");
      tb.innerHTML = data.length
        ? data
            .map(function (x) {
              var itemRows = (x.items || [])
                .map(function (i) {
                  return (
                    '<div class="transfer-table-item"><span>' +
                    esc(i.item_code) +
                    "</span><span>" +
                    esc(i.item_name) +
                    "</span><span>" +
                    esc(
                      i.item_type === "Finished Good"
                        ? i.size || "----"
                        : "----",
                    ) +
                    "</span><span>" +
                    InventoryApp.qty(i.quantity) +
                    "</span><span>" +
                    esc(i.unit || "") +
                    "</span></div>"
                  );
                })
                .join("");
              return (
                "<tr><td>" +
                esc(x.transfer_number) +
                "</td><td>" +
                esc(x.date) +
                '</td><td><div class="transfer-group"><div class="transfer-group-head"><b>' +
                esc(x.from_warehouse_code || "") +
                " (ID: " +
                esc(x.from_warehouse) +
                ")</b><span>→</span><b>" +
                esc(x.to_warehouse_code || "") +
                " (ID: " +
                esc(x.to_warehouse) +
                ')</b></div><div class="transfer-items-head"><span>Code</span><span>Item</span><span>Size</span><span>Qty</span><span>Unit</span></div>' +
                itemRows +
                '</div></td><td><span class="status ' +
                (x.status === "Delivered" || x.status === "Completed"
                  ? "active"
                  : x.status === "Cancelled"
                    ? "critical"
                    : "low") +
                '">' +
                esc(x.status) +
                "</span></td></tr>"
              );
            })
            .join("")
        : '<tr><td colspan="4" class="empty">No transfer records found.</td></tr>';
      document.querySelectorAll(".admin-only").forEach(function (el) {
        el.style.display = document.body.dataset.role === "ADMIN" ? "" : "";
      });
    }
    function openNew() {
      if (!ready)
        loadDependencies(function () {
          openNew();
        });
      else {
        editing = null;
        form.reset();
        document.getElementById("transferNumber").value = "TRF-" + Date.now();
        document.getElementById("date").value = today();
        renderWarehouseSelects();
        list.innerHTML = "";
        addItemRow();
        InventoryApp.openModal("transferModal");
      }
    }
    // Bind immediately so the button remains functional even if an API request fails.
    add.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      openNew();
    });
    document.getElementById("filterBtn").onclick = render;
    document.getElementById("tableSearch").oninput = render;
    typeFilter.onchange = render;
    if (itemFilter) itemFilter.onchange = render;
    document.getElementById("fromWarehouse").onchange = function () {
      list.querySelectorAll(".transfer-item-row").forEach(updateAvailability);
    };
    document.getElementById("addTransferItem").onclick = function () {
      addItemRow();
    };
    list.addEventListener("change", function (e) {
      var row = e.target.closest(".transfer-item-row");
      if (!row) return;
      if (e.target.classList.contains("transfer-type")) {
        var type = e.target.value,
          sel = row.querySelector(".transfer-item");
        sel.disabled = !type;
        sel.innerHTML = itemOptions(type, "");
        updateAvailability(row);
      } else if (e.target.classList.contains("transfer-item"))
        updateAvailability(row);
    });
    list.addEventListener("click", function (e) {
      if (e.target.classList.contains("remove-transfer-item")) {
        var rs = list.querySelectorAll(".transfer-item-row");
        if (rs.length > 1) e.target.closest(".transfer-item-row").remove();
      }
    });
    document.getElementById("transactionTable").onclick = function (e) {
      var b = e.target.closest("button");
      if (!b) return;
      var id = b.dataset.edit || b.dataset.delete;
      if (!id) return;
      if (b.dataset.delete) {
        if (confirm("Delete this transfer?"))
          req(
            "php/stock-transfer.php",
            { action: "delete", id: id },
            "POST",
            function (r) {
              InventoryApp.toast(r.message);
              if (r.success) loadRows();
            },
          );
        return;
      }
      var x = rows.find(function (a) {
        return String(a.id) === String(id);
      });
      if (!x) return;
      editing = id;
      form.reset();
      document.getElementById("transferNumber").value = x.transfer_number;
      document.getElementById("date").value = x.date;
      renderWarehouseSelects(x.from_warehouse, x.to_warehouse);
      document.getElementById("reason").value = x.reason || "";
      document.getElementById("remarks").value = x.remarks || "";
      list.innerHTML = "";
      (x.items || []).forEach(function (i) {
        addItemRow({
          type: i.item_type,
          item_id: i.item_id,
          quantity: i.quantity,
        });
      });
      InventoryApp.openModal("transferModal");
    };
    form.onsubmit = function (e) {
      e.preventDefault();
      var transferItems = [];
      list.querySelectorAll(".transfer-item-row").forEach(function (r) {
        var type = r.querySelector(".transfer-type").value,
          item = r.querySelector(".transfer-item").value,
          qty = r.querySelector(".transfer-qty").value;
        if (type && item && Number(qty) > 0)
          transferItems.push({ item_id: Number(item), quantity: Number(qty) });
      });
      if (!transferItems.length) {
        InventoryApp.toast("Add at least one item.");
        return;
      }
      var data = {
        action: editing ? "update" : "create",
        id: editing || "",
        transfer_number: document.getElementById("transferNumber").value,
        date: document.getElementById("date").value,
        from_warehouse: document.getElementById("fromWarehouse").value,
        to_warehouse: document.getElementById("toWarehouse").value,
        reason: document.getElementById("reason").value,
        remarks: document.getElementById("remarks").value,
        items: JSON.stringify(transferItems),
      };
      req("php/stock-transfer.php", data, "POST", function (r) {
        var m = form.querySelector(".form-message");
        m.className = "form-message " + (r.success ? "success" : "error");
        m.textContent = r.message;
        InventoryApp.toast(r.message);
        if (r.success) {
          InventoryApp.closeModal(modal);
          loadRows();
        }
      });
    };
    loadDependencies(function () {
      renderWarehouseSelects();
      populateItemFilter();
      loadRows();
    });
  }
  if (document.body.dataset.page === "stock-transfer") init();
})();
