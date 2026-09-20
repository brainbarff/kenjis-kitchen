const onlineData = {
    categories: [
        { id: 'all', name: 'All' },
        { id: 'student', name: 'Student Meals' },
        { id: 'addons', name: 'Add-Ons' },
        { id: 'pares', name: 'Pulutan & Pares' },
        { id: 'silog', name: 'Silog Meals' },
        { id: 'sets', name: 'Set Meals' },
        { id: 'bilao', name: 'Bilao' }
    ],
    items: [
        {
            id: 1,
            category: 'student',
            name: 'Pastil Rice',
            description: 'Sulit student meal with rice and flavorful pastil topping.',
            price: 30,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1604908176997-125f25cc6f3d?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 2,
            category: 'student',
            name: 'Burgersteak Rice',
            description: 'Affordable burgersteak rice meal for students.',
            price: 35,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1569058242567-93de6f36f8eb?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 3,
            category: 'pares',
            name: 'Beef Pares',
            description: 'Classic beef pares served hot and savory.',
            price: 65,
            popular: false,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 4,
            category: 'silog',
            name: 'Tapsilog',
            description: 'Tapa, sinangag, and egg meal.',
            price: 155,
            popular: false,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 5,
            category: 'sets',
            name: 'Set Meal I',
            description: '2 Pastil Rice, Ham, Shanghai, and Egg.',
            price: 170,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1609183480237-ccbb2d7c5772?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 6,
            category: 'bilao',
            name: 'Spaghetti Bilao Small',
            description: 'Small bilao tray for sharing.',
            price: 500,
            popular: false,
            stock: 'out',
            image: 'https://images.unsplash.com/photo-1488477181946-6428a0291777?auto=format&fit=crop&w=900&q=80'
        }
    ],
    portions: [
        { id: 'regular', name: 'Regular', add: 0 },
        { id: 'large', name: 'Large', add: 35 }
    ],
    extras: [
        { id: 'egg', name: 'Extra Egg', price: 20 },
        { id: 'cheese', name: 'Extra Cheese', price: 25 },
        { id: 'sauce', name: 'Extra Sauce', price: 15 },
        { id: 'spicy', name: 'Spicy Level Up', price: 10 }
    ],
    history: [
        {
            orderNo: 'ONL-20260820-001',
            date: 'Aug 20, 2026',
            status: 'Delivered',
            items: ['Pastil Rice', 'Burgersteak Rice'],
            itemIds: [1, 2],
            total: 65.00
        },
        {
            orderNo: 'ONL-20260818-014',
            date: 'Aug 18, 2026',
            status: 'Picked Up',
            items: ['Beef Pares', 'Set Meal I'],
            itemIds: [3, 5],
            total: 235.00
        }
    ]
};
