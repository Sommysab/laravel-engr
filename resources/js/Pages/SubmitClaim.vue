<template>
    <GuestLayout>
        <Head title="Submit Claim" />

        <div class="min-h-screen bg-gray-50 py-8">
            <div class="max-w-4xl mx-auto">
                <div class="bg-white shadow-lg rounded-lg p-8">
                    <div class="mb-8">
                        <h1 class="text-3xl font-bold text-gray-900">Submit Healthcare Claim</h1>
                        <p class="mt-2 text-gray-600">Please fill in all required information to submit your claim.</p>
                    </div>

                    <form @submit.prevent="submitClaim" class="space-y-8">
                        <!-- Provider and Insurer Information -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="provider_name" value="Provider Name" class="mb-2" />
                                <TextInput
                                    id="provider_name"
                                    v-model="form.provider_name"
                                    type="text"
                                    class="w-full"
                                    placeholder="Enter provider name"
                                    required
                                />
                                <InputError :message="errors.provider_name" class="mt-1" />
                            </div>

                            <div>
                                <InputLabel for="insurer_code" value="Insurer Code" class="mb-2" />
                                <select
                                    id="insurer_code"
                                    v-model="form.insurer_code"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    required
                                >
                                    <option value="">Select Insurer</option>
                                    <option v-for="insurer in availableInsurers" :key="insurer.code" :value="insurer.code">
                                        {{ insurer.code }} - {{ insurer.name }}
                                    </option>
                                </select>
                                <InputError :message="errors.insurer_code" class="mt-1" />
                            </div>
                        </div>

                        <!-- Date and Specialty Information -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <InputLabel for="encounter_date" value="Encounter Date" class="mb-2" />
                                <input
                                    id="encounter_date"
                                    v-model="form.encounter_date"
                                    type="date"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    required
                                />
                                <InputError :message="errors.encounter_date" class="mt-1" />
                            </div>

                            <div>
                                <InputLabel for="specialty" value="Specialty" class="mb-2" />
                                <select
                                    id="specialty"
                                    v-model="form.specialty"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    required
                                >
                                    <option value="">Select Specialty</option>
                                    <option v-for="specialty in specialties" :key="specialty" :value="specialty">
                                        {{ specialty }}
                                    </option>
                                </select>
                                <InputError :message="errors.specialty" class="mt-1" />
                            </div>

                            <div>
                                <InputLabel for="priority_level" value="Priority Level" class="mb-2" />
                                <select
                                    id="priority_level"
                                    v-model="form.priority_level"
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    required
                                >
                                    <option value="">Select Priority</option>
                                    <option v-for="priority in priorities" :key="priority.value" :value="priority.value">
                                        {{ priority.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.priority_level" class="mt-1" />
                            </div>
                        </div>

                        <!-- Claim Items Section -->
                        <div class="border-t pt-8">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-xl font-semibold text-gray-900">Claim Items</h2>
                                <button
                                    type="button"
                                    @click="addItem"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                    Add Item
                                </button>
                            </div>

                            <div class="space-y-4">
                                <div v-for="(item, index) in form.items" :key="index" class="bg-gray-50 p-4 rounded-lg">
                                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                                        <div class="md:col-span-2">
                                            <InputLabel :for="`item_name_${index}`" value="Item Name" class="mb-1" />
                                            <TextInput
                                                :id="`item_name_${index}`"
                                                v-model="item.name"
                                                type="text"
                                                class="w-full"
                                                placeholder="Enter item name"
                                                required
                                            />
                                        </div>

                                        <div>
                                            <InputLabel :for="`unit_price_${index}`" value="Unit Price ($)" class="mb-1" />
                                            <input
                                                :id="`unit_price_${index}`"
                                                v-model.number="item.unit_price"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                placeholder="0.00"
                                                required
                                                @input="calculateSubtotal(index)"
                                            />
                                        </div>

                                        <div>
                                            <InputLabel :for="`quantity_${index}`" value="Quantity" class="mb-1" />
                                            <input
                                                :id="`quantity_${index}`"
                                                v-model.number="item.quantity"
                                                type="number"
                                                min="1"
                                                class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                placeholder="1"
                                                required
                                                @input="calculateSubtotal(index)"
                                            />
                                        </div>

                                        <div class="flex items-end space-x-2">
                                            <div class="flex-1">
                                                <InputLabel value="Subtotal ($)" class="mb-1" />
                                                <input
                                                    :value="formatCurrency(item.subtotal || 0)"
                                                    type="text"
                                                    class="w-full border-gray-300 bg-gray-100 rounded-md shadow-sm"
                                                    readonly
                                                />
                                            </div>
                                            
                                            <button
                                                v-if="form.items.length > 1"
                                                type="button"
                                                @click="removeItem(index)"
                                                class="inline-flex items-center p-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-900 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                                title="Remove item"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <InputError :message="errors.items" class="mt-2" />
                        </div>

                        <!-- Total Amount Display -->
                        <div class="border-t pt-6">
                            <div class="bg-indigo-50 p-6 rounded-lg">
                                <div class="flex justify-between items-center">
                                    <span class="text-lg font-semibold text-gray-900">Total Claim Amount:</span>
                                    <span class="text-2xl font-bold text-indigo-600">{{ formatCurrency(totalAmount) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Section -->
                        <div class="border-t pt-6">
                            <div class="flex justify-end space-x-4">
                                <SecondaryButton type="button" @click="resetForm">
                                    Reset Form
                                </SecondaryButton>
                                
                                <PrimaryButton
                                    type="submit"
                                    :disabled="!isFormValid || submitting"
                                    class="inline-flex items-center"
                                >
                                    <svg v-if="submitting" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ submitting ? 'Submitting...' : 'Submit Claim' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </form>

                    <!-- Success Message -->
                    <div v-if="submitSuccess" class="mt-6 bg-green-50 border border-green-200 rounded-md p-4">
                        <div class="flex">
                            <svg class="flex-shrink-0 h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-green-800">Claim Submitted Successfully!</h3>
                                <div class="mt-2 text-sm text-green-700">
                                    <p>Your claim has been submitted and is being processed. Claim ID: <strong>{{ submittedClaimId }}</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

// Form data
const form = ref({
    provider_name: '',
    insurer_code: '',
    encounter_date: '',
    specialty: '',
    priority_level: 3,
    items: [
        {
            name: '',
            unit_price: 0,
            quantity: 1,
            subtotal: 0
        }
    ]
})

// Component state
const errors = ref({})
const submitting = ref(false)
const submitSuccess = ref(false)
const submittedClaimId = ref(null)
const availableInsurers = ref([])

// Static data options
const specialties = [
    "Cardiology",
    "Neurology",
    "Surgery",
    "General Practice",
    "Pediatrics",
    "Dermatology",
]

const priorities = [
    { value: 1, label: '1 - Urgent' },
    { value: 2, label: '2 - High' },
    { value: 3, label: '3 - Normal' },
    { value: 4, label: '4 - Low' },
    { value: 5, label: '5 - Routine' }
]

// Computed properties
const totalAmount = computed(() => {
    return form.value.items.reduce((total, item) => {
        return total + (item.subtotal || 0)
    }, 0)
})

const isFormValid = computed(() => {
    return form.value.provider_name &&
           form.value.insurer_code &&
           form.value.encounter_date &&
           form.value.specialty &&
           form.value.priority_level &&
           form.value.items.length > 0 &&
           form.value.items.every(item => item.name && item.unit_price > 0 && item.quantity > 0)
})

// Methods
const calculateSubtotal = (index) => {
    const item = form.value.items[index]
    item.subtotal = (item.unit_price || 0) * (item.quantity || 0)
}

const addItem = () => {
    form.value.items.push({
        name: '',
        unit_price: 0,
        quantity: 1,
        subtotal: 0
    })
}

const removeItem = (index) => {
    if (form.value.items.length > 1) {
        form.value.items.splice(index, 1)
    }
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount)
}

const resetForm = () => {
    form.value = {
        provider_name: '',
        insurer_code: '',
        encounter_date: '',
        specialty: '',
        priority_level: 3,
        items: [
            {
                name: '',
                unit_price: 0,
                quantity: 1,
                subtotal: 0
            }
        ]
    }
    errors.value = {}
    submitSuccess.value = false
    submittedClaimId.value = null
}

const loadInsurers = async () => {
    try {
        const response = await fetch('/api/v1/insurers')
        const data = await response.json()
        if (data.success) {
            availableInsurers.value = data.data
        }
    } catch (error) {
        console.error('Failed to load insurers:', error)
    }
}

const submitClaim = async () => {
    if (!isFormValid.value) return

    submitting.value = true
    errors.value = {}

    try {
        const response = await fetch('/api/v1/claims', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            },
            body: JSON.stringify(form.value)
        })

        const data = await response.json()

        if (response.ok && data.success) {
            submitSuccess.value = true
            submittedClaimId.value = data.data.claim.id
            
            // Optionally reset form after successful submission
            setTimeout(() => {
                resetForm()
            }, 3000)
        } else {
            if (data.errors) {
                errors.value = data.errors
            } else {
                errors.value.general = data.message || 'An error occurred while submitting the claim.'
            }
        }
    } catch (error) {
        console.error('Submission error:', error)
        errors.value.general = 'Network error. Please check your connection and try again.'
    } finally {
        submitting.value = false
    }
}

// Initialize component
onMounted(() => {
    loadInsurers()
})
</script>
