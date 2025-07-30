function updateBrokerName(select) {
    document.getElementById('broker_name').value = select.options[select.selectedIndex].dataset.name;
}
function generateCycleInputs() {
    let count = document.getElementById("cycle_count").value;
    let container = document.getElementById("cycle_inputs");
    container.innerHTML = "";
    for (let i = 0; i < count; i++) {
        container.innerHTML += `<input type="text" name="cycles[]" required>`;
    }
}
