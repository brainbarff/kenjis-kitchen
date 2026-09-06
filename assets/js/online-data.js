const onlineData = {
    categories: [
        { id: 'all', name: 'All' },
        { id: 'mains', name: 'Mains' },
        { id: 'noodles', name: 'Noodles' },
        { id: 'drinks', name: 'Drinks' },
        { id: 'desserts', name: 'Desserts' },
        { id: 'sides', name: 'Sides' }
    ],
    items: [
        {
            id: 1,
            category: 'mains',
            name: 'Chicken Teriyaki Bowl',
            description: 'Grilled chicken glazed with teriyaki sauce, served with rice and vegetables.',
            price: 145,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1604908176997-125f25cc6f3d?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 2,
            category: 'mains',
            name: 'Pork Katsudon',
            description: 'Crispy pork cutlet with egg and onions over steamed rice.',
            price: 155,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1569058242567-93de6f36f8eb?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 3,
            category: 'noodles',
            name: 'Beef Ramen',
            description: 'Warm ramen with beef strips, egg, spring onions, and savory broth.',
            price: 180,
            popular: false,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 4,
            category: 'drinks',
            name: 'House Iced Tea',
            description: 'Refreshing house blend iced tea served cold.',
            price: 55,
            popular: false,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 5,
            category: 'sides',
            name: 'Gyoza',
            description: 'Pan-fried dumplings with a light dipping sauce.',
            price: 95,
            popular: true,
            stock: 'available',
            image: 'https://images.unsplash.com/photo-1609183480237-ccbb2d7c5772?auto=format&fit=crop&w=900&q=80'
        },
        {
            id: 6,
            category: 'desserts',
            name: 'Mango Panna Cotta',
            description: 'Creamy dessert topped with fresh mango sauce.',
            price: 85,
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
            items: ['Chicken Teriyaki Bowl', 'House Iced Tea'],
            itemIds: [1, 4],
            total: 224.40
        },
        {
            orderNo: 'ONL-20260818-014',
            date: 'Aug 18, 2026',
            status: 'Picked Up',
            items: ['Beef Ramen', 'Gyoza'],
            itemIds: [3, 5],
            total: 308.00
        }
    ]
};
