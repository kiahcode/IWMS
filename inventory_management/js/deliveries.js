var DeliveryPage = {
  init: function () {
    var rows = [],
      role = "USER",
      canControl = false;
    function load() {
      InventoryApp.request(
        "php/deliveries.php",
        { action: "list" },
        "GET",
        function (r) {
          if (r.success) {
            rows = r.rows || [];
            render();
          }
        },
      );
    }
    function openRecord(id) {
      var x = rows.find(function (r) {
        return String(r.id) === String(id);
      });
      if (!x) return;
      var body = document.getElementById("deliveryRecordDetails");
      var supplier = (x.items || [])
        .map(function (it) {
          return it.supplier_name || "";
        })
        .filter(Boolean)
        .filter(function (v, i, a) {
          return a.indexOf(v) === i;
        })
        .join(", ");
      body.innerHTML =
        '<div class="form-grid"><label>Request Number<input readonly value="' +
        InventoryApp.esc(x.request_number || "") +
        '"></label><label>Destination<input readonly value="' +
        InventoryApp.esc(x.destination || "") +
        '"></label><label>Requested By<input readonly value="' +
        InventoryApp.esc(x.requester || "") +
        '"></label><label>Status<input readonly value="' +
        InventoryApp.esc(x.status || "") +
        '"></label>' +
        (String(x.destination || "").toLowerCase() === "procurement"
          ? '<label>PO Number<input readonly value="' +
            InventoryApp.esc(x.po_number || "Not provided by Procurement") +
            '"></label>'
          : "") +
        '<label>Supplier<input id="inspectionSupplier" value="' +
        InventoryApp.esc(supplier) +
        '" placeholder="Supplier name"></label></div><div class="delivery-items-editor">' +
        (x.items || [])
          .map(function (it) {
            var target = x.is_inventory_request
              ? Number(it.quantity || 0)
              : Number(it.approved_quantity || 0);
            var delivered = Number(it.delivered_quantity || 0);
            var rejected = Number(it.inspection_rejected_quantity || 0);
            var accepted =
              rejected > 0
                ? Number(it.inspection_accepted_quantity || 0)
                : Number(it.inspection_accepted_quantity || delivered);
            return (
              '<div class="delivery-item-editor inspection-item" data-ri="' +
              it.id +
              '"><div><small>Item</small><b>' +
              InventoryApp.esc(it.item_name || "") +
              "</b><br><span>" +
              InventoryApp.esc(it.item_code || "") +
              "</span><small>Ordered / Approved: " +
              InventoryApp.qty(target) +
              " " +
              InventoryApp.esc(it.unit || "") +
              '</small></div><label><small>Delivered</small><input type="number" min="0" max="' +
              target +
              '" step="1" value="' +
              delivered +
              '" data-ri="' +
              it.id +
              '" class="delivered-qty"></label><label><small>Accepted</small><input type="number" min="0" max="' +
              target +
              '" step="1" value="' +
              accepted +
              '" class="inspection-accepted"></label><label><small>Rejected</small><input type="number" min="0" max="' +
              target +
              '" step="1" value="' +
              rejected +
              '" class="inspection-rejected"></label><label class="reason-field"><small>Rejection Reason</small><input class="inspection-reason" value="' +
              InventoryApp.esc(it.inspection_rejection_reason || "") +
              '" placeholder="Reason (required if rejected)"></label></div>'
            );
          })
          .join("") +
        "</div>";
      body.querySelectorAll(".inspection-item").forEach(function (row) {
        var delivered = row.querySelector(".delivered-qty"),
          accepted = row.querySelector(".inspection-accepted"),
          rejected = row.querySelector(".inspection-rejected");
        function sync() {
          var d = Math.max(0, Number(delivered.value || 0)),
            a = Math.max(0, Number(accepted.value || 0)),
            r = Math.max(0, Number(rejected.value || 0));
          if (document.activeElement === rejected) {
            accepted.value = Math.max(0, d - r);
          } else if (document.activeElement === accepted) {
            rejected.value = Math.max(0, d - a);
          } else {
            accepted.value = d;
            rejected.value = 0;
          }
          accepted.max = d;
          rejected.max = d;
        }
        delivered.addEventListener("input", function () {
          accepted.value = delivered.value;
          rejected.value = 0;
          sync();
        });
        accepted.addEventListener("input", sync);
        rejected.addEventListener("input", sync);
      });
      var canEdit = canControl && x.status !== "Delivered";
      document.getElementById("saveDeliveryBtn").style.display = canEdit
        ? "inline-flex"
        : "none";
      document.getElementById("saveDeliveryBtn").onclick = function () {
        var items = [];
        var valid = true;
        body.querySelectorAll(".inspection-item").forEach(function (row) {
          var d = Number(row.querySelector(".delivered-qty").value || 0),
            a = Number(row.querySelector(".inspection-accepted").value || 0),
            r = Number(row.querySelector(".inspection-rejected").value || 0),
            reason = row.querySelector(".inspection-reason").value.trim();
          if (
            !Number.isInteger(d) ||
            !Number.isInteger(a) ||
            !Number.isInteger(r) ||
            a + r !== d ||
            (r > 0 && !reason)
          )
            valid = false;
          items.push({
            request_item_id: Number(row.dataset.ri),
            delivered_quantity: d,
            accepted_quantity: a,
            rejected_quantity: r,
            rejection_reason: reason,
          });
        });
        if (!valid) {
          InventoryApp.toast(
            "Accepted + Rejected must equal Delivered. Add a reason for every rejected item.",
          );
          return;
        }
        InventoryApp.request(
          "php/deliveries.php",
          {
            action: "record_delivery",
            id: id,
            items: JSON.stringify(items),
            supplier_name: document.getElementById("inspectionSupplier").value,
          },
          "POST",
          function (r) {
            InventoryApp.toast(r.message);
            if (r.success) {
              InventoryApp.closeModal(
                document.getElementById("deliveryRecordModal"),
              );
              load();
            }
          },
        );
      };
      InventoryApp.openModal("deliveryRecordModal");
    }
    function render() {
      var q = (
        document.getElementById("deliverySearch").value || ""
      ).toLowerCase();
      var data = rows.filter(function (x) {
        return JSON.stringify(x).toLowerCase().indexOf(q) >= 0;
      });
      document.getElementById("deliveryTable").innerHTML =
        data
          .map(function (x) {
            var status = x.status,
              statusClass =
                status === "Accepted" ||
                status === "Delivered" ||
                status === "Out for Delivery"
                  ? "active"
                  : status === "Partially Delivered"
                    ? "partial"
                    : "low";
            var acceptable =
              !x.is_inventory_request &&
              !x.received_by &&
              !!x.all_delivered &&
              (status === "Out for Delivery" ||
                status === "Partially Delivered");
            var action =
              canControl &&
              x.status !== "Delivered" &&
              (!!x.is_inventory_request || !x.received_by)
                ? '<button class="action-btn" data-record="' +
                  x.id +
                  '">' +
                  (x.is_inventory_request
                    ? "Accept / Record Receipt"
                    : "Record Delivery") +
                  "</button> "
                : "";
            if (acceptable)
              action +=
                '<button class="action-btn primary-action" data-accept="' +
                x.id +
                '">Confirm Receipt</button>';
            var itemText = (x.items || [])
              .map(function (i) {
                return i.item_name;
              })
              .join(", ");
            var total = Number(x.qty_total || 0),
              remaining = Number(x.remaining_balance_total || 0);
            return (
              '<tr><td><button class="action-btn dr-link" data-dr="' +
              x.dr_id +
              '">' +
              InventoryApp.esc(x.dr_number || "") +
              "</button></td><td>" +
              InventoryApp.esc(x.request_number) +
              "</td><td>" +
              InventoryApp.esc(x.requester || "") +
              "</td><td><b>" +
              InventoryApp.esc(x.destination || "") +
              "</b></td><td>" +
              InventoryApp.esc(x.recipient_name || "") +
              "</td><td>" +
              InventoryApp.esc(itemText) +
              "</td><td>—</td><td>" +
              InventoryApp.qty(total) +
              "</td><td>" +
              InventoryApp.qty(remaining) +
              "</td><td>" +
              InventoryApp.esc(x.expected_days || "") +
              '</td><td><span class="status ' +
              statusClass +
              '">' +
              InventoryApp.esc(status) +
              "</span></td><td>" +
              action +
              "</td></tr>"
            );
          })
          .join("") ||
        '<tr><td colspan="12" class="empty">No deliveries found.</td></tr>';
    }
    InventoryApp.session(function (r) {
      role = String(r.user.role || "USER").toUpperCase();
      canControl = !!r.user.can_control || role === "ADMIN";
      load();
      window.clearInterval(window.__deliveryTimer);
      window.__deliveryTimer = window.setInterval(load, 5000);
    });
    document.getElementById("deliverySearch").oninput = render;
    document.getElementById("deliveryTable").onclick = function (e) {
      var b = e.target.closest("button");
      if (!b) return;
      if (b.dataset.record) {
        openRecord(b.dataset.record);
        return;
      }
      if (b.dataset.accept) {
        if (!confirm("Accept this complete delivery?")) return;
        InventoryApp.request(
          "php/deliveries.php",
          { action: "accept", id: b.dataset.accept },
          "POST",
          function (r) {
            InventoryApp.toast(r.message);
            if (r.success) load();
          },
        );
        return;
      }
      var dr = b.dataset.dr;
      if (dr) {
        InventoryApp.request(
          "php/delivery-receipts.php",
          { action: "details", id: dr },
          "GET",
          function (r) {
            if (!r.success) {
              InventoryApp.toast(r.message);
              return;
            }
            var d = r.receipt || {},
              items = d.items || [];
            document.getElementById("drDetails").innerHTML =
              '<div class="form-grid"><label>DR Number<input readonly value="' +
              InventoryApp.esc(d.dr_number || "") +
              '"></label><label>Receipt Date<input readonly value="' +
              InventoryApp.esc(d.receipt_date || "") +
              '"></label><label>Request Number<input readonly value="' +
              InventoryApp.esc(d.request_number || "") +
              '"></label><label>Destination<input readonly value="' +
              InventoryApp.esc(d.destination || "") +
              '"></label><label>Recipient<input readonly value="' +
              InventoryApp.esc(d.recipient_name || "") +
              '"></label><label>Requested By<input readonly value="' +
              InventoryApp.esc(d.requester || "") +
              '"></label></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Warehouse</th><th>Location</th><th>Requested</th><th>Delivered</th><th>Unit</th></tr></thead><tbody>' +
              items
                .map(function (i) {
                  return (
                    "<tr><td>" +
                    InventoryApp.esc(i.item_name) +
                    "</td><td>" +
                    InventoryApp.esc(i.warehouse_name) +
                    "</td><td>" +
                    InventoryApp.esc(i.location || "") +
                    "</td><td>" +
                    InventoryApp.qty(i.quantity) +
                    "</td><td>" +
                    InventoryApp.qty(i.delivered_quantity || 0) +
                    "</td><td>" +
                    InventoryApp.esc(i.unit || "") +
                    "</td></tr>"
                  );
                })
                .join("") +
              "</tbody></table></div>";
            InventoryApp.openModal("drDetailsModal");
          },
        );
      }
    };
  },
};
document.addEventListener("DOMContentLoaded", function () {
  DeliveryPage.init();
});
